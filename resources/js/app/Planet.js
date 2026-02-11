import { EventBus } from '../event-bus';
import Modal from './Modal';

export default Modal.extend({
    props: ['url', 'planetImageUrl'],

    data() {
        return {
            geoJsonPoint: {
                properties: {
                    status: ''
                },
                geometry: {
                    coordinates: []
                }
            },
            planet: {
                id: undefined
            },
            data: {
                resource_id: null,
                username: ''
            }
        };
    },

    created() {
        EventBus.$on('planet-click', this.open);
        EventBus.$on('planet-updated', planet => { this.planet = planet; });
    },

    computed: {
        isCurrent() {
            return this.properties.id === this.planet.id;
        },

        isFriendly() {
            return this.properties.status === 'friendly';
        },

        properties() {
            return this.geoJsonPoint.properties;
        },

        geometry() {
            return this.geoJsonPoint.geometry;
        },

        planetPreviewUrl() {
            if (this.data && this.data.resource_id && this.planetImageUrl) {
                return this.planetImageUrl.replace('__resource__', this.data.resource_id);
            }

            return null;
        }
    },

    methods: {
        open(geoJsonPoint) {
            this.geoJsonPoint = geoJsonPoint;
            this.fetchData();
        },

        fetchData() {
            axios.get(
                this.url.replace('__planet__', this.properties.id)
            ).then(response => {
                this.data = response.data;
                this.$nextTick(() => this.$modal.modal());
            });
        },

        openUser() {
            this.openAfterHidden(
                () => EventBus.$emit('profile-click', this.data.username)
            );
        },

        changePlanet() {
            EventBus.$emit('change-planet', this.properties.id);
        },

        jumpToSurface() {
            // Close the Bootstrap modal immediately – do NOT depend on the
            // hidden.bs.modal CSS-transition event (openAfterHidden) because
            // it can silently fail on mobile browsers, background tabs, or
            // when CSS transitions are throttled.
            this.$modal.modal('hide');

            // Clean up modal artifacts right away so nothing blocks the
            // surface view (backdrop overlay, body scroll lock).
            this.$nextTick(() => {
                $('.modal-backdrop').remove();
                $('body').removeClass('modal-open')
                    .css({ overflow: '', 'padding-right': '' });
            });

            // Navigate to the surface (home) route.
            this.$router.push({ name: 'home' }).catch(err => {
                // If Vue Router rejects (NavigationDuplicated, etc.), fall
                // back to a hard navigation so the user always reaches the
                // surface page.
                if (!err || err.name !== 'NavigationDuplicated') {
                    window.location.href = '/';
                }
            });
        },

        openMove(type) {
            this.openAfterHidden(
                () => EventBus.$emit('move-click', type, _.assignIn({}, this.properties, this.data))
            );
        }
    }
});
