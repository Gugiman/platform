import { Mixin } from 'src/core/shopware';
import utils from 'src/core/service/util.service';
import './sw-single-select.scss';
import template from './sw-single-select.html.twig';

export default {
    name: 'sw-single-select',
    template,

    mixins: [
        Mixin.getByName('validation')
    ],

    props: {
        options: {
            required: true,
            type: [Array, Object]
        },
        value: {
            required: true
        },
        searchPlaceholder: {
            type: String,
            required: false,
            default: ''
        },
        placeholder: {
            type: String,
            required: false,
            default: ''
        },
        label: {
            type: [String, Object],
            default: ''
        },
        helpText: {
            type: String,
            required: false,
            default: ''
        },
        labelProperty: {
            type: String,
            required: false,
            default: 'label'
        },
        valueProperty: {
            type: String,
            required: false,
            default: 'value'
        },
        required: {
            type: Boolean,
            required: false,
            default: false
        },
        disabled: {
            type: Boolean,
            required: false,
            default: false
        },
        showSearch: {
            type: Boolean,
            required: false,
            default: true
        }
    },

    data() {
        return {
            searchTerm: '',
            isExpanded: false,
            activeResultPosition: 1,
            isLoading: false,
            singleSelection: null,
            currentOptions: []
        };
    },

    computed: {
        selectClasses() {
            return {
                'has--error': this.hasError,
                'is--disabled': this.disabled,
                'is--expanded': this.isExpanded,
                'is--searchable': this.showSearch
            };
        },

        hasError() {
            return !!this.$attrs.error;
        },

        selectId() {
            return `sw-single-select--${utils.createId()}`;
        },

        placeholderOption() {
            if (!this.required) {
                const label = this.placeholder || this.$tc('global.sw-single-select.valuePlaceholder');
                return { [this.valueProperty]: null, [this.labelProperty]: label };
            }

            return null;
        }
    },

    created() {
        this.createdComponent();
    },

    destroyed() {
        this.destroyedComponent();
    },

    watch: {
        // Update the view if the value changes from outside
        value() {
            if (this.singleSelection && this.singleSelection[this.valueProperty] !== this.value) {
                this.init();
            } else if (!this.singleSelection && this.value !== null) {
                this.init();
            }
        },

        options: {
            deep: true,
            handler() {
                this.init();
            }
        }
    },

    methods: {
        createdComponent() {
            this.init();
            this.addEventListeners();
        },

        init() {
            this.currentOptions = [];

            this.options.forEach((item) => {
                this.currentOptions.push(item);
            });

            this.initPlaceholder();

            this.loadSelected();
        },

        initPlaceholder() {
            if (this.placeholderOption) {
                this.currentOptions.unshift(this.placeholderOption);
            }
        },

        destroyedComponent() {
            this.removeEventListeners();
        },

        addEventListeners() {
            this.$on('option-click', this.setValue);
            this.$on('option-mouse-over', this.setActiveResultPosition);
            document.addEventListener('click', this.closeOnClickOutside);
            document.addEventListener('keyup', this.closeOnClickOutside);
        },

        removeEventListeners() {
            document.removeEventListener('click', this.closeOnClickOutside);
            document.removeEventListener('keyup', this.closeOnClickOutside);
        },

        loadSelected() {
            if (this.value === null || this.value === '' || this.value === undefined) {
                this.singleSelection = this.placeholderOption;
                return;
            }
            this.resolveKey(this.value).then((item) => {
                this.singleSelection = item;
            });
        },

        resolveKey(key) {
            const found = this.currentOptions.find((item) => {
                return (item[this.valueProperty] === key);
            });

            return Promise.resolve(found);
        },

        search() {
            this.$emit('search-term-change', this.searchTerm);
        },

        unsetValue() {
            this.singleSelection = null;
            this.updateInputElement();
        },

        updateInputElement() {
            if (this.singleSelection === null) {
                this.$emit('input', null);
                return;
            }

            this.$emit('input', this.singleSelection[this.valueProperty]);
        },

        isSelected(item) {
            if (this.singleSelection === null) {
                return false;
            }
            return this.singleSelection[this.valueProperty] === item[this.valueProperty];
        },

        setValue({ item }) {
            if (item === undefined) {
                if (this.isExpanded) {
                    this.closeResultList();
                }
                return;
            }

            item = JSON.parse(JSON.stringify(item));
            if (item[this.labelProperty] !== null && item[this.labelProperty].constructor === String) {
                item[this.labelProperty] = item[this.labelProperty].replace(/<[^>]+>/g, '');
            }

            this.singleSelection = item;

            this.updateInputElement();
            this.closeResultList();
        },

        openResultList() {
            if (this.isExpanded === false) {
                this.scrollToResultsTop();
            }

            this.isExpanded = true;
            this.emitActiveResultPosition();
        },

        closeResultList() {
            this.$nextTick(() => {
                this.isExpanded = false;
            });

            this.activeResultPosition = 0;
            this.searchTerm = '';

            if (!this.showSearch) {
                return;
            }

            this.$nextTick(() => {
                if (!this.$refs.swSelectInput) {
                    return;
                }

                this.$refs.swSelectInput.blur();
            });
        },

        setFocus() {
            this.openResultList();

            if (!this.showSearch) {
                return;
            }
            /*
             * since the input is not visible at first we need to wait a tick until the
             * result list with the input is visible
             */
            this.$nextTick(() => {
                if (!this.$refs.swSelectInput) {
                    return;
                }

                this.$refs.swSelectInput.focus();
            });
        },

        closeOnClickOutside(event) {
            if (event.type === 'keyup' && event.key && event.key.toLowerCase() !== 'tab') {
                return;
            }

            const target = event.target;

            if (target.closest('.sw-single-select') !== this.$refs.swSelect) {
                this.isExpanded = false;
                this.activeResultPosition = 0;
            }
        },

        setActiveResultPosition({ index }) {
            this.activeResultPosition = index;
            this.emitActiveResultPosition();
        },

        emitActiveResultPosition() {
            this.$emit('active-item-index-select', this.activeResultPosition);
        },

        navigateUpResults() {
            this.$emit('on-arrow-up', this.activeResultPosition);

            if (this.activeResultPosition === 0) {
                return;
            }

            this.setActiveResultPosition({ index: this.activeResultPosition - 1 });

            const swSelectEl = this.$refs.swSelect;
            const resultItem = swSelectEl.querySelector('.sw-single-select-option');
            const resultContainer = swSelectEl.querySelector('.sw-single-select__results');

            if (!resultItem) {
                return;
            }

            resultContainer.scrollTop -= resultItem.offsetHeight;
        },

        navigateDownResults() {
            this.$emit('on-arrow-down', this.activeResultPosition);

            const optionsCount = this.currentOptions.length;

            if (this.activeResultPosition === optionsCount - 1 || optionsCount < 1) {
                return;
            }

            this.setActiveResultPosition({ index: this.activeResultPosition + 1 });

            const swSelectEl = this.$refs.swSelect;
            const activeItem = swSelectEl.querySelector('.is--active');
            const itemHeight = swSelectEl.querySelector('.sw-single-select-option').offsetHeight;


            if (!activeItem) {
                return;
            }

            const activeItemPosition = activeItem ? activeItem.offsetTop + itemHeight : 0;
            const resultContainer = swSelectEl.querySelector('.sw-single-select__results');
            let resultContainerHeight = resultContainer.offsetHeight;

            resultContainerHeight -= itemHeight;

            if (activeItemPosition > resultContainerHeight) {
                resultContainer.scrollTop += itemHeight;
            }
        },

        onScroll(event) {
            this.$emit('scroll', event);
        },

        scrollToResultsTop() {
            this.setActiveResultPosition({ index: 0 });

            if (!this.$refs.swSelect.querySelector('.sw-single-select__results')) {
                return;
            }

            this.$refs.swSelect.querySelector('.sw-single-select__results').scrollTop = 0;
        },

        onKeyUpEnter() {
            this.$emit('on-keyup-enter', this.activeResultPosition);
        }
    }
};
