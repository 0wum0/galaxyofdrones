import { EventBus } from '../event-bus';
import Remaining from './Remaining';

export default Remaining.extend({
    data() {
        return {
            $modal: undefined,
            isEnabled: false
        };
    },

    mounted() {
        this.$modal = $(this.$el)
            .on('show.bs.modal', () => {
                EventBus.$emit('modal-show');
                this.isEnabled = true;
            })
            .on('hidden.bs.modal', () => {
                EventBus.$emit('modal-hidden');
                this.isEnabled = false;
            });
    },

    watch: {
        $route() {
            this.close();
        }
    },

    methods: {
        openAfterHidden(callback) {
            let fired = false;

            const handler = () => {
                if (fired) return;
                fired = true;
                clearTimeout(timer);
                callback();
                EventBus.$off('modal-hidden', handler);
            };

            EventBus.$on('modal-hidden', handler);

            this.close();

            // Safety net: if hidden.bs.modal never fires (CSS transition
            // throttled, mobile browser quirk, etc.), force the callback
            // after a generous timeout so the UI never gets stuck.
            const timer = setTimeout(handler, 400);
        },

        close() {
            this.$modal.modal('hide');
        }
    }
});
