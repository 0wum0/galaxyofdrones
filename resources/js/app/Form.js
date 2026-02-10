import Modal from './Modal';

export default Modal.extend({
    props: ['url'],

    data() {
        return {
            isSubmitted: false,
            errors: {},
            form: {}
        };
    },

    created() {
        this.form = this.values();
    },

    computed: {
        method() {
            return 'post';
        },

        parameters() {
            return JSON.parse(JSON.stringify(this.form));
        }
    },

    methods: {
        hasError(name) {
            return _.has(this.errors, name);
        },

        values() {
            return {};
        },

        error(name) {
            return _.first(this.errors[name]);
        },

        submit() {
            if (this.isSubmitted) {
                return;
            }

            this.isSubmitted = true;

            axios[this.method](this.url, this.parameters)
                .then(this.handleSuccess)
                .catch(this.handleError);
        },

        handleSuccess() {
            this.isSubmitted = false;
            this.errors = {};
            this.close();
        },

        handleError(error) {
            this.isSubmitted = false;

            // Only populate form field errors for 422 (validation) responses.
            // Other status codes (401, 403, 419, 500) are handled by the
            // global axios interceptor and should not overwrite form state.
            if (_.get(error, 'response.status') === 422) {
                this.errors = _.get(error, 'response.data.errors', {});
            }
        }
    }
});
