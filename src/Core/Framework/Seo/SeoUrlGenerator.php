<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Seo;

use Cocur\Slugify\Bridge\Twig\SlugifyExtension;
use Cocur\Slugify\Slugify;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityDeletedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Field\AssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\TranslatedField;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Write\EntityExistence;
use Shopware\Core\Framework\Seo\Exception\InvalidTemplateException;
use Shopware\Core\Framework\Seo\SeoUrl\SeoUrlEntity;
use Shopware\Core\Framework\Seo\SeoUrlRoute\SeoUrlMapping;
use Shopware\Core\Framework\Seo\SeoUrlRoute\SeoUrlRouteConfig;
use Shopware\Core\Framework\Seo\SeoUrlRoute\SeoUrlRouteInterface;
use Shopware\Core\Framework\Seo\SeoUrlTemplate\TemplateGroup;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Storefront\Framework\Seo\SeoTemplateReplacementVariable;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;
use Twig\Environment;
use Twig\Error\Error;
use Twig\Error\SyntaxError;
use Twig\Extension\EscaperExtension;
use Twig\Loader\ArrayLoader;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\GetAttrExpression;
use Twig\Node\Expression\NameExpression;
use Twig\Node\Node;
use Twig\Source;

class SeoUrlGenerator
{
    public const ESCAPE_SLUGIFY = 'slugifyurlencode';

    /**
     * @var RouterInterface
     */
    private $router;

    /**
     * @var Environment
     */
    private $twig;

    /**
     * @var DefinitionInstanceRegistry
     */
    private $definitionRegistry;

    /**
     * @var RequestStack
     */
    private $requestStack;

    public function __construct(
        DefinitionInstanceRegistry $definitionRegistry,
        Slugify $slugify,
        RouterInterface $router,
        RequestStack $requestStack
    ) {
        $this->definitionRegistry = $definitionRegistry;

        $this->router = $router;

        $this->initTwig($slugify);
        $this->requestStack = $requestStack;
    }

    /**
     * @param TemplateGroup[] $templateGroups
     *
     * @throws InvalidTemplateException
     * @throws \Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException
     */
    public function generateSeoUrls(Context $context, SeoUrlRouteInterface $seoUrlRoute, array $ids, array $templateGroups, ?SeoUrlRouteConfig $configOverride = null): iterable
    {
        $criteria = new Criteria($ids);
        $seoUrlRoute->prepareCriteria($criteria);

        $config = $configOverride ?? $seoUrlRoute->getConfig();
        $defaultTemplate = $config->getTemplate();

        $repo = $this->definitionRegistry->getRepository($config->getDefinition()->getEntityName());

        $entities = $context->disableCache(static function (Context $context) use ($repo, $criteria) {
            return $repo->search($criteria, $context)->getEntities();
        });

        foreach ($templateGroups as $templateGroup) {
            $template = $templateGroup->getTemplate() ?: $defaultTemplate;
            $config->setTemplate($template);
            $this->setTwigTemplate($config);

            yield from $this->generate($seoUrlRoute, $config, $templateGroup->getSalesChannels(), $entities);
        }
    }

    public function checkUpdateAffectsTemplate(
        EntityWrittenContainerEvent $event,
        EntityDefinition $definition,
        array $specialVariables,
        string $template
    ): bool {
        $accessors = $this->extractVariableAccessorsFromTemplate($template);
        $relevantDefinitions = $this->mapAccessorsToEntities($definition, $specialVariables, $accessors);

        return $this->checkEventRelevance($event, $relevantDefinitions);
    }

    private function initTwig(Slugify $slugify): void
    {
        $this->twig = new Environment(new ArrayLoader());
        $this->twig->setCache(false);
        $this->twig->enableStrictVariables();
        $this->twig->addExtension(new SlugifyExtension($slugify));

        /** @var EscaperExtension $coreExtension */
        $coreExtension = $this->twig->getExtension(EscaperExtension::class);
        $coreExtension->setEscaper(
            self::ESCAPE_SLUGIFY,
            static function ($twig, $string) use ($slugify) {
                return rawurlencode($slugify->slugify($string));
            }
        );
    }

    private function generate(SeoUrlRouteInterface $seoUrlRoute, SeoUrlRouteConfig $config, array $salesChannels, EntityCollection $entities): iterable
    {
        $request = $this->requestStack->getMasterRequest();

        $basePath = $request ? $request->getBasePath() : '';

        /** @var Entity $entity */
        foreach ($entities as $entity) {
            $seoUrl = new SeoUrlEntity();
            $seoUrl->setForeignKey($entity->getUniqueIdentifier());

            $seoUrl->setIsCanonical(true);
            $seoUrl->setIsModified(false);
            $seoUrl->setIsDeleted(false);

            /** @var SalesChannelEntity|null $salesChannel */
            foreach ($salesChannels as $salesChannel) {
                $copy = clone $seoUrl;

                $mapping = $seoUrlRoute->getMapping($entity, $salesChannel);
                $pathInfo = $this->router->generate($config->getRouteName(), $mapping->getInfoPathContext());
                $pathInfo = $this->removePrefix($pathInfo, $basePath);

                $copy->setPathInfo($pathInfo);

                $seoPathInfo = $this->getSeoPathInfo($mapping, $config);

                if ($seoPathInfo === null || $seoPathInfo === '') {
                    continue;
                }

                $copy->setSeoPathInfo($seoPathInfo);

                if ($salesChannel !== null) {
                    $copy->setSalesChannelId($salesChannel->getId());
                } else {
                    $copy->setSalesChannelId(null);
                }

                yield $copy;
            }
        }
    }

    private function getSeoPathInfo(SeoUrlMapping $mapping, SeoUrlRouteConfig $config): ?string
    {
        try {
            return trim($this->twig->render('template', $mapping->getSeoPathInfoContext()));
        } catch (Error $error) {
            if (!$config->getSkipInvalid()) {
                throw $error;
            }

            return null;
        }
    }

    private function setTwigTemplate(SeoUrlRouteConfig $config): void
    {
        $template = $config->getTemplate();
        $template = "{% autoescape '" . self::ESCAPE_SLUGIFY . "' %}$template{% endautoescape %}";
        $this->twig->setLoader(new ArrayLoader(['template' => $template]));

        try {
            $this->twig->loadTemplate('template');
        } catch (SyntaxError $syntaxError) {
            if (!$config->getSkipInvalid()) {
                throw new InvalidTemplateException('Syntax error: ' . $syntaxError->getMessage());
            }
        }
    }

    private function removePrefix(string $subject, string $prefix): string
    {
        if (!$prefix || mb_strpos($subject, $prefix) !== 0) {
            return $subject;
        }

        return mb_substr($subject, mb_strlen($prefix));
    }

    private function extractVariableAccessorsFromTemplate(string $template): array
    {
        $rootNode = $this->twig->parse($this->twig->tokenize(new Source($template, '__check')));
        $nodeStack = $rootNode->getIterator()->getArrayCopy();
        $accessors = [];
        do {
            /** @var Node $node */
            $node = array_pop($nodeStack);
            $childNodes = $node->getIterator()->getArrayCopy();
            if ($node instanceof GetAttrExpression) {
                $accessedMembers = [];
                $attrChildren = $node->getIterator()->getArrayCopy();
                /* @var Node $attrChild */
                while (!empty($attrChildren)) {
                    $attrChild = array_pop($attrChildren);
                    if ($attrChild instanceof NameExpression) {
                        $accessedMembers[] = $attrChild->getAttribute('name');
                    } elseif ($attrChild instanceof ConstantExpression) {
                        $accessedMembers[] = $attrChild->getAttribute('value');
                    }
                    $attrChildren = array_merge($attrChildren, $attrChild->getIterator()->getArrayCopy());
                }
                $accessors[] = array_reverse($accessedMembers);
            } else {
                $nodeStack = array_merge($nodeStack, $childNodes);
            }
        } while (!empty($nodeStack));

        return $accessors;
    }

    private function mapAccessorsToEntities(EntityDefinition $definition, array $specialVariables, array $accessors): array
    {
        $relevantDefinitions = [$definition->getEntityName() => []];
        foreach ($accessors as $accessor) {
            if (empty($accessor)) {
                continue;
            }
            if ($accessor[0] !== $definition->getEntityName()) {
                continue;
            }
            $accessor = array_slice($accessor, 1);

            $currentDefinition = $definition;
            foreach ($accessor as $fieldName) {
                if (array_key_exists($fieldName, $specialVariables)) {
                    /** @var SeoTemplateReplacementVariable $replacement */
                    $replacement = $specialVariables[$fieldName];

                    if (!key_exists($replacement->getMappedEntityName(), $relevantDefinitions)) {
                        $relevantDefinitions[$replacement->getMappedEntityName()] = [];
                    }

                    if ($replacement->hasMappedFields()) {
                        $relevantDefinitions[$replacement->getMappedEntityName()][] = $replacement->getMappedEntityFields();
                    } else {
                        $currentDefinition = $this->definitionRegistry->getByEntityName($replacement->getMappedEntityName());
                    }
                    continue;
                }

                $accessedField = $currentDefinition->getField($fieldName);

                if ($accessedField instanceof AssociationField) {
                    $currentDefinition = $accessedField->getReferenceDefinition();
                    if (!key_exists($currentDefinition->getEntityName(), $relevantDefinitions)) {
                        $relevantDefinitions[$currentDefinition->getEntityName()] = [];
                    }
                } elseif ($accessedField instanceof TranslatedField) {
                    $translationDefinition = $currentDefinition->getTranslationDefinition();
                    if (!key_exists($translationDefinition->getEntityName(), $relevantDefinitions)) {
                        $relevantDefinitions[$translationDefinition->getEntityName()] = [];
                    }
                    $relevantDefinitions[$translationDefinition->getEntityName()][] = $accessedField->getPropertyName();
                } elseif ($accessedField !== null) {
                    $relevantDefinitions[$currentDefinition->getEntityName()][] = $accessedField->getPropertyName();
                }
            }
        }

        return $relevantDefinitions;
    }

    private function checkEventRelevance(EntityWrittenContainerEvent $event, array $relevantDefinitions): bool
    {
        foreach ($relevantDefinitions as $relevantDefinitionName => $relevantFields) {
            $relevantEvents = $event->getEventByEntityName($relevantDefinitionName);

            // This Entity was not written. Continue.
            if ($relevantEvents === null) {
                continue;
            }

            // A relevant entity was deleted. We need to update the affected seo urls in any case.
            if ($relevantEvents instanceof EntityDeletedEvent) {
                return true;
            }

            $newEntities = array_filter($relevantEvents->getExistences(), function (EntityExistence $existence) {
                return !$existence->exists();
            });
            // This relevant entity did not exist previously. A initial url has to be generated.
            if (count($newEntities) > 0) {
                return true;
            }

            // The relevant entities existed previously. Check if any relevant field was written.
            $payloadKeys = array_keys(array_merge(...$relevantEvents->getPayloads()));
            if (!empty(array_intersect($payloadKeys, $relevantFields))) {
                return true;
            }
        }

        return false;
    }
}
