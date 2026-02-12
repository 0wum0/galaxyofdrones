<template>
    <div class="starmap">
        <div v-if="dataError" class="starmap-error-overlay">
            {{ dataError }}
        </div>
    </div>
</template>
<script>
import { EventBus } from '../event-bus';

export default {
    props: {
        size: {
            type: Number,
            required: true
        },

        maxZoom: {
            type: Number,
            required: true
        },

        geoJsonUrl: {
            type: String,
            required: true
        },

        tileUrl: {
            type: String,
            required: true
        },

        imagePath: {
            type: String,
            required: true
        },

        zoomInTitle: {
            type: String,
            required: true
        },

        zoomOutTitle: {
            type: String,
            required: true
        },

        bookmarkTitle: {
            type: String,
            required: true
        }
    },

    data() {
        return {
            geoJsonLayer: undefined,
            map: undefined,
            zoom: 0,
            dataError: '',
            planet: {
                id: undefined,
                x: 0,
                y: 0
            }
        };
    },

    created() {
        this.zoom = Math.ceil(Math.log(this.size / 256) / Math.log(2));
    },

    mounted() {
        EventBus.$on('planet-updated', this.planetUpdated);
        EventBus.$on('starmap-move', this.starmapMove);

        // After the component is mounted and the DOM is ready,
        // invalidate the map size so Leaflet recalculates its dimensions.
        this.$nextTick(() => {
            if (this.map) {
                this.map.invalidateSize(true);
            }
        });
    },

    beforeDestroy() {
        EventBus.$off('planet-updated', this.planetUpdated);
        EventBus.$off('starmap-move', this.starmapMove);

        this.destoryLeaflet();
    },

    methods: {
        planetUpdated(planet) {
            const isSamePlanet = planet.id === this.planet.id;

            this.planet = planet;

            if (!this.map) {
                this.initLeaflet();
            } else if (isSamePlanet) {
                this.refreshGeoJson();
            } else {
                this.map.setView(this.center(), this.maxZoom);
            }
        },

        starmapMove(x, y) {
            this.map.setView(
                this.unproject(x, y), this.maxZoom
            );
        },

        initLeaflet() {
            L.Icon.Default.imagePath = `${this.imagePath}/`;

            this.map = L.map(this.$el, {
                attributionControl: false,
                boxZoom: false,
                crs: L.CRS.Simple,
                minZoom: 0,
                maxZoom: this.maxZoom,
                zoomControl: false
            });

            this.map.setView(this.center(), this.maxZoom);

            L.tileLayer(this.tileUrl, {
                noWrap: true,
                bounds: L.latLngBounds(this.southWest(), this.northEast())
            }).addTo(this.map);

            const coordsToLatLng = coords => this.map.unproject([
                coords[0], coords[1]
            ], this.maxZoom);

            // ── GeoJSON layer with initial AJAX fetch ────────────────
            // leaflet-ajax's L.geoJson.ajax() fires its XHR in a
            // Promise microtask — which runs AFTER the synchronous
            // ajaxParams.headers assignment below.  Headers are
            // therefore set in time and the request is authenticated.
            this.geoJsonLayer = L.geoJson.ajax(this.geoJson(), {
                coordsToLatLng,

                pointToLayer: (geoJsonPoint, latLng) => {
                    if (geoJsonPoint.properties.is_movement) {
                        return this.movementMarker(
                            latLng, coordsToLatLng(geoJsonPoint.properties.end), geoJsonPoint
                        );
                    }

                    return this.objectMarker(latLng, geoJsonPoint);
                },

                style: feature => {
                    if (feature.geometry.type === 'LineString') {
                        return {
                            className: `leaflet-movement ${this.movementClassName(feature)}`
                        };
                    }

                    return {};
                }
            });

            this.geoJsonLayer.ajaxParams.headers = axios.defaults.headers.common;
            this.geoJsonLayer.addTo(this.map);

            // ── Flicker-free data refresh on pan/zoom ────────────────
            // Fetch new GeoJSON first, then clear + add atomically so
            // the browser never paints an empty frame.
            this._pendingRefresh = null;

            this.map.on('moveend', () => this.refreshGeoJson());

            this.zoomControl().addTo(this.map);
            this.bookmarkControl().addTo(this.map);

            // ── Event stopping (prevent browser zoom/scroll gestures) ──
            // Call preventDefault() so touch/wheel events don't bubble
            // to the browser's native zoom/scroll. Leaflet's own
            // handlers still fire (they listen on the same element).
            this._preventTouch = e => e.preventDefault();
            this._preventWheel = e => e.preventDefault();
            this.$el.addEventListener('touchmove', this._preventTouch, { passive: false });
            this.$el.addEventListener('wheel', this._preventWheel, { passive: false });

            // Expose the map instance globally for debugging and
            // external invalidateSize() calls (e.g. route transitions).
            window.__starmap = this.map;

            // Force Leaflet to recalculate container size after all
            // layers/controls are added. nextTick + rAF ensures the
            // DOM has fully settled (critical on mobile).
            this.$nextTick(() => {
                requestAnimationFrame(() => {
                    this.map.invalidateSize(true);
                });
            });

            // ── Safety net: retry if initial AJAX produces no data ───
            // If L.geoJson.ajax's initial load fails or returns empty,
            // retry once via refreshGeoJson() after a short delay.
            this.geoJsonLayer.on('data:loaded', () => {
                if (!this.geoJsonLayer.getLayers().length && !this.dataError) {
                    setTimeout(() => {
                        if (this.map && !this.geoJsonLayer.getLayers().length) {
                            this.refreshGeoJson();
                        }
                    }, 1500);
                }
            });
        },

        destoryLeaflet() {
            if (!this.map) {
                return;
            }

            // Remove event-stopping listeners added in initLeaflet().
            if (this._preventTouch) {
                this.$el.removeEventListener('touchmove', this._preventTouch, { passive: false });
                this._preventTouch = null;
            }
            if (this._preventWheel) {
                this.$el.removeEventListener('wheel', this._preventWheel, { passive: false });
                this._preventWheel = null;
            }

            this.map.remove();
            this.map = undefined;
            window.__starmap = undefined;
        },

        /**
         * Flicker-free GeoJSON refresh: fetch first, then swap layers
         * atomically so the browser never paints an empty frame.
         *
         * If the new response is empty but the layer already has data,
         * keep the existing markers visible (don't clear to blank).
         */
        refreshGeoJson() {
            const url = this.geoJson();

            if (this._pendingRefresh) {
                this._pendingRefresh.cancelled = true;
            }

            const ticket = { cancelled: false };
            this._pendingRefresh = ticket;

            axios.get(url).then(response => {
                if (ticket.cancelled) return;
                this.dataError = '';

                var features = response.data
                    && response.data.features
                    && response.data.features.length
                    ? response.data.features
                    : null;

                if (features) {
                    // Atomic swap: clear + add in the same synchronous
                    // block so the browser never renders a blank frame.
                    this.geoJsonLayer.clearLayers();
                    this.geoJsonLayer.addData(response.data);
                } else if (!this.geoJsonLayer.getLayers().length) {
                    // No existing markers AND response empty — zoom might
                    // be too low (< 7) or viewport has no objects yet.
                    // This is normal at low zoom levels; only show a hint
                    // if we're at zoom >= 7 (where data is expected).
                    if (this.map.getZoom() >= 7) {
                        this.dataError = 'No objects in view — try zooming in or panning.';
                    }
                }
                // else: response empty but existing markers present →
                // keep showing current markers (e.g. boundary pan).
            }).catch(err => {
                // Keep existing markers visible on error (no clear).
                console.error('[StarMap] GeoJSON fetch failed', err);
                this.dataError = 'Map data could not be loaded — retrying…';

                // Retry once after 3 seconds.
                setTimeout(() => {
                    if (this.map && !this._destroyed) {
                        this.dataError = '';
                        this.refreshGeoJson();
                    }
                }, 3000);
            });
        },

        geoJson() {
            const bounds = this.map.getPixelBounds();
            const multiplier = this.multiplier();

            return this.geoJsonUrl
                .replace('__zoom__', this.map.getZoom())
                .replace('__bounds__', [
                    bounds.min.x * multiplier,
                    bounds.min.y * multiplier,
                    bounds.max.x * multiplier,
                    bounds.max.y * multiplier
                ].join(','));
        },

        center() {
            return this.unproject(
                this.planet.x, this.planet.y
            );
        },

        southWest() {
            return this.unproject(
                0, 0
            );
        },

        northEast() {
            return this.unproject(
                this.size, this.size
            );
        },

        unproject(x, y) {
            return this.map.unproject([
                x, y
            ], this.zoom);
        },

        multiplier() {
            return 2 ** (this.maxZoom - this.map.getZoom());
        },

        zoomControl() {
            return L.control.zoom({
                zoomInTitle: this.zoomInTitle,
                zoomOutTitle: this.zoomOutTitle
            });
        },

        bookmarkControl() {
            const BookmarkControl = L.Control.extend({
                options: {
                    position: 'topleft',
                    bookmarkTitle: this.bookmarkTitle,
                    bookmarkIconClass: 'fas fa-star'
                },

                onAdd() {
                    const container = L.DomUtil.create('div', 'leaflet-bar leaflet-control leaflet-control-bookmark');
                    const link = L.DomUtil.create('a', 'leaflet-control-bookmark', container);

                    link.href = '#';
                    link.title = this.options.bookmarkTitle;
                    link.onclick = e => {
                        e.preventDefault();
                        EventBus.$emit('bookmark-click');
                    };

                    L.DomUtil.create('i', this.options.bookmarkIconClass, link);

                    return container;
                }
            });

            return new BookmarkControl();
        },

        objectMarker(latLng, geoJsonPoint) {
            const size = (geoJsonPoint.properties.size + 16) / this.multiplier();
            const props = geoJsonPoint.properties;

            let className = 'leaflet-icon-object';

            if (props.type === 'planet') {
                className += ` leaflet-planet leaflet-planet-${props.resource_id || 1} ${props.status}`;
            } else if (props.type === 'star') {
                className += ' leaflet-star';
            }

            const options = {
                className,
                iconSize: [
                    size, size
                ]
            };

            if (this.map.getZoom() >= 8) {
                options.html = `<span>${props.name}</span>`;
            }

            const marker = L.marker(latLng, {
                title: geoJsonPoint.properties.name,
                icon: L.divIcon(options)
            });

            marker.on('click', () => EventBus.$emit(`${geoJsonPoint.properties.type}-click`, geoJsonPoint));

            return marker;
        },

        movementMarker(latLng, endLatLng, geoJsonPoint) {
            L.MovementMarker = L.Marker.extend({
                options: {
                    end: endLatLng,
                    interval: geoJsonPoint.properties.interval
                },

                onAdd(map) {
                    L.Marker.prototype.onAdd.call(this, map);

                    if (this._icon) {
                        this._icon.style[L.DomUtil.TRANSITION] = `all ${this.options.interval - 1}s linear`;
                    }

                    if (this._shadow) {
                        this._shadow.style[L.DomUtil.TRANSITION] = `all ${this.options.interval - 1}s linear`;
                    }

                    setTimeout(
                        () => this.setLatLng(this.options.end), 1000
                    );
                }
            });

            const size = 32 / this.multiplier();

            const options = {
                className: `leaflet-icon-movement ${this.movementClassName(geoJsonPoint)} size-${size}`,
                iconSize: [
                    size, size
                ]
            };

            if (this.map.getZoom() >= 8) {
                const angleOffset = 45;
                const angle = Math.atan2(endLatLng.lng - latLng.lng, endLatLng.lat - latLng.lat);

                const angleDeg = ((angle > 0
                    ? angle
                    : (2 * Math.PI + angle)) * 360) / (2 * Math.PI) - angleOffset;

                options.html = `<i class="fas fa-rocket" style="${L.DomUtil.TRANSFORM}: translateX(-50%) translateY(-50%) rotate(${angleDeg}deg)"></i>`;
            }

            return new L.MovementMarker(latLng, {
                icon: L.divIcon(options)
            });
        },

        movementClassName(feature) {
            if (feature.properties.type === 'expedition') {
                return feature.properties.type;
            }

            if (feature.properties.type < 3) {
                return `${feature.properties.status}-attack`;
            }

            return feature.properties.status;
        }
    }
};
</script>
