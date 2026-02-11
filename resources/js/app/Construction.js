import { EventBus } from '../event-bus';
import Modal from './Modal';

export default Modal.extend({
    props: [
        'url',
        'storeUrl',
        'destroyUrl'
    ],

    data() {
        return {
            isSubscribed: false,
            canDemolish: false,
            energy: 0,
            selected: {
                id: undefined
            },
            grid: {
                id: undefined,
                building_id: undefined
            },
            data: {
                remaining: 0,
                buildings: []
            }
        };
    },

    created() {
        EventBus.$on('grid-click', this.open);
        EventBus.$on('energy-updated', energy => { this.energy = energy; });
        EventBus.$on('planet-update', () => this.fetchData());
    },

    computed: {
        canConstruct() {
            return this.energy >= this.selected.construction_cost;
        }
    },

    methods: {
        open(grid) {
            this.grid = grid;
            this.fetchData(true);
        },

        fetchData(showModal = false) {
            if (!showModal && !this.isEnabled) {
                return;
            }

            axios.get(
                this.url.replace('__grid__', this.grid.id)
            ).then(response => {
                this.data = response.data;

                if (!showModal || !this.data.buildings.length) {
                    this.close();
                } else {
                    this.initRemaining(this.data.remaining);

                    if (!_.find(this.data.buildings, this.selected)) {
                        this.select(
                            _.first(this.data.buildings)
                        );
                    }

                    if (showModal) {
                        this.$nextTick(() => this.$modal.modal());
                    }
                }
            });
        },

        store() {
            axios.post(
                this.storeUrl.replace('__grid__', this.grid.id).replace('__building__', this.selected.id)
            ).then(() => {
                this.close();
                // Trigger planet data refresh so Surface redraws with
                // the new construction state (timer, ghost sprite).
                EventBus.$emit('planet-update');
            });
        },

        destroy() {
            axios.delete(
                this.destroyUrl.replace('__grid__', this.grid.id)
            ).then(() => {
                this.close();
                EventBus.$emit('planet-update');
            });
        },

        isSelected(building) {
            return this.selected.id === building.id;
        },

        select(building) {
            this.selected = building;
        }
    }
});
