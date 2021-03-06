import { Component, Mixin } from 'src/core/shopware';
import Criteria from 'src/core/data-new/criteria.data';
import template from './sw-sales-channel-detail-theme.html.twig';
import './sw-sales-channel-detail-theme.scss';

Component.register('sw-sales-channel-detail-theme', {
    template,

    mixins: [
        Mixin.getByName('notification'),
        Mixin.getByName('placeholder')
    ],

    inject: [
        'repositoryFactory',
        'context',
        'themeService'
    ],

    props: {
        salesChannel: {
            required: true
        },

        isLoading: {
            type: Boolean,
            default: false
        }
    },

    data() {
        return {
            theme: null,
            showThemeSelectionModal: false,
            showChangeModal: false,
            newThemeId: null
        };
    },

    computed: {
        themeRepository() {
            return this.repositoryFactory.create('theme');
        }
    },

    watch: {
        'salesChannel.extensions.themes': {
            deep: true,
            handler() {
                if (!this.salesChannel.extensions || this.salesChannel.extensions.themes.length < 1) {
                    return;
                }

                this.theme = this.salesChannel.extensions.themes[0];

                this.getTheme(this.theme.id);
            }
        }
    },

    created() {
        this.createdComponent();
    },

    methods: {
        createdComponent() {
            if (!this.salesChannel ||
                !this.salesChannel.extensions ||
                this.salesChannel.extensions.themes.length < 1) {
                return;
            }

            this.theme = this.salesChannel.extensions.themes[0];
            this.getTheme(this.theme.id);
        },

        getTheme(themeId) {
            if (themeId === null) {
                return;
            }

            const criteria = new Criteria();
            criteria.addAssociation('previewMedia');

            this.themeRepository.get(themeId, this.context, criteria).then((theme) => {
                this.theme = theme;
            });
        },

        openThemeModal() {
            this.showThemeSelectionModal = true;
        },

        closeThemeModal() {
            this.showThemeSelectionModal = false;
        },

        openInThemeManager() {
            if (!this.theme) {
                this.$router.push({ name: 'sw.theme.manager.index' });
            } else {
                this.$router.push({ name: 'sw.theme.manager.detail', params: { id: this.theme.id } });
            }
        },

        onChangeTheme(themeId) {
            this.showThemeSelectionModal = false;

            this.newThemeId = themeId;
            this.showChangeModal = true;
        },

        onCloseChangeModal() {
            this.showChangeModal = false;
            this.newThemeId = null;
        },

        onConfirmChange() {
            if (this.newThemeId) {
                this.onThemeSelect(this.newThemeId);
            }

            this.showChangeModal = false;
            this.newThemeId = null;
        },

        onThemeSelect(selectedThemeId) {
            this.themeService.assignTheme(selectedThemeId, this.salesChannel.id);
            this.getTheme(selectedThemeId);
        }
    }
});
