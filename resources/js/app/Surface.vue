<template>
    <div class="surface-viewport" ref="viewport">
        <canvas ref="canvas" class="surface"></canvas>
        <div v-if="!ready" class="surface-loading">
            <div class="surface-spinner"></div>
        </div>
        <p v-if="errorMessage" class="surface-error-text">
            {{ errorMessage }}
        </p>
    </div>
</template>
<script>
import {
    autoDetectRenderer, Container, Loader, Sprite, utils, Text, Texture
} from 'pixi.js';

import { EventBus } from '../event-bus';
import Filters from './Filters';
import Sprites from './Sprites';

export default {
    props: {
        width: {
            type: Number,
            required: true
        },

        height: {
            type: Number,
            required: true
        },

        backgroundTexture: {
            type: String,
            required: true
        },

        gridTextureAtlas: {
            type: String,
            required: true
        }
    },

    data() {
        return {
            $viewport: undefined,
            animationFrame: undefined,
            clickTreshold: 5,
            isDragging: false,
            dragStartX: 0,
            dragStartY: 0,
            dragged: 0,
            container: undefined,
            intervals: [],
            loader: undefined,
            renderer: undefined,
            stage: undefined,
            ready: false,
            retryCount: 0,
            maxRetries: 3,
            errorMessage: '',
            breakpoints: [
                {
                    minWidth: 1,
                    maxHeight: 592,
                    ratio: 0.64
                },
                {
                    minWidth: 992,
                    maxHeight: 765,
                    ratio: 0.827
                },
                {
                    minWidth: 1200,
                    maxHeight: false,
                    ratio: 1
                }
            ],
            planet: {
                resource_id: undefined,
                grids: []
            },
            textStyle: {
                fontFamily: 'Exo 2',
                fontSize: '14px',
                fill: '#fff',
                align: 'center',
                stroke: '#0e141c',
                strokeThickness: 4
            }
        };
    },

    created() {
        utils.skipHello();
    },

    mounted() {
        this._destroyed = false;
        this._hasPlanetData = false;
        this.$viewport = $(this.$el);

        EventBus.$on('planet-updated', this.planetUpdated);

        // ── Data acquisition: 3 parallel strategies ──────────────
        //
        // Strategy 1 (instant): Ask the Sidebar to re-emit its cached
        // planet data.  Works when navigating FROM the starmap, because
        // the Sidebar already fetched the planet earlier.
        EventBus.$emit('planet-data-request');

        // Strategy 2 (immediate): If the Sidebar didn't have data (e.g.
        // first page load, API still in-flight), fire our own request
        // RIGHT AWAY.  The old code waited 1.5 s — that delay was the
        // main reason for the visible "black screen".
        this.$nextTick(() => {
            if (!this._destroyed && !this._hasPlanetData) {
                this.fetchPlanetDirect();
            }
        });

        // Strategy 3 (safety net): One more EventBus retry after 3 s
        // in case both the above strategies fail (e.g. EventBus listener
        // wasn't ready yet, network was slow for the direct call).
        this._retryTimer = setTimeout(() => {
            if (!this._destroyed && !this._hasPlanetData) {
                EventBus.$emit('planet-data-request');
            }
        }, 3000);
    },

    beforeDestroy() {
        this._destroyed = true;

        EventBus.$off('planet-updated', this.planetUpdated);

        if (this._retryTimer) {
            clearTimeout(this._retryTimer);
            this._retryTimer = undefined;
        }

        if (this._loadTimeout) {
            clearTimeout(this._loadTimeout);
            this._loadTimeout = undefined;
        }

        this.destroyPixi();
    },

    methods: {
        // ─────────────── URL helper ───────────────────────────────
        /**
         * Strip the origin from an absolute URL so it becomes root-
         * relative.  This prevents the PixiJS resource-loader from
         * treating same-server assets as cross-origin (which happens
         * when APP_URL uses a different protocol/host than the actual
         * page) and avoids WebGL "tainted texture" failures.
         */
        toLocalPath(url) {
            if (!url) return url;
            try {
                const parsed = new URL(url, window.location.origin);
                return parsed.pathname + parsed.search;
            } catch (e) {
                return url.replace(/^https?:\/\/[^/]+/, '');
            }
        },

        // ─────────────── CSS fallback background ──────────────────
        /**
         * Apply the planet background image via CSS on the viewport
         * element.  This is visible IMMEDIATELY (no PixiJS required)
         * and serves as a reliable fallback if WebGL fails.
         */
        applyCssBackground() {
            if (!this.planet.resource_id || !this.$refs.viewport) return;

            const url = this.toLocalPath(this.background());
            const el = this.$refs.viewport;
            el.style.backgroundImage = `url(${url})`;
        },

        // ─────────────── Data fetching ────────────────────────────
        /**
         * Fetch the logged-in user's current planet directly from the
         * API.  GET /api/planet returns auth()->user()->current, so no
         * planet ID is needed — the backend resolves it from the
         * session.
         */
        fetchPlanetDirect() {
            if (this._fetchInFlight) return;
            this._fetchInFlight = true;

            axios.get('/api/planet').then(response => {
                this._fetchInFlight = false;
                if (!this._destroyed && response.data && response.data.id) {
                    this.planetUpdated(response.data);
                }
            }).catch(err => {
                this._fetchInFlight = false;
                console.error('[Surface] API fetch failed:', err);
                if (!this._destroyed && !this._hasPlanetData) {
                    this.errorMessage = 'Planet data could not be loaded.';
                }
            });
        },

        // ─────────────── Planet data handler ──────────────────────
        planetUpdated(planet) {
            if (this._destroyed) return;

            // Guard: only accept objects that actually look like planet data.
            if (!planet || !planet.resource_id) return;

            // Prevent double-init when both EventBus AND direct fetch deliver.
            if (this._hasPlanetData && this.stage) {
                // Data refresh — update the existing scene.
                this.planet = planet;
                this.applyCssBackground();
                this.updatePixi();
                return;
            }

            this._hasPlanetData = true;
            this.planet = planet;

            // 1) Show the planet image via CSS immediately — visible
            //    through the semi-transparent loading overlay.
            this.applyCssBackground();

            // 2) Start the PixiJS pipeline (loads textures, then renders
            //    the interactive grid on top of the background).
            if (!this.stage && !this._loading) {
                this.$viewport = $(this.$el);
                this.initPixi();
            }
        },

        // ─────────────── PixiJS lifecycle ─────────────────────────
        initPixi() {
            if (this._destroyed) return;

            this._loading = true;
            this.errorMessage = '';

            this.loader = new Loader();

            this.loader.onError.add((error, _loader, resource) => {
                console.error('[Surface] Loader error:', resource.url, error);
            });

            const bgUrl = this.toLocalPath(this.background());
            const gridUrl = this.toLocalPath(this.gridTextureAtlas);

            this.loader.add({ name: this.backgroundName(), url: bgUrl, crossOrigin: '' });
            this.loader.add({ name: 'grid', url: gridUrl, crossOrigin: '' });

            this._loadTimeout = setTimeout(() => {
                if (this._loading && !this._destroyed) {
                    console.error('[Surface] Texture load timed out.');
                    this.destroyPixi();
                    this.retryInit();
                }
            }, 15000);

            this.loader.load(() => {
                if (this._loadTimeout) {
                    clearTimeout(this._loadTimeout);
                    this._loadTimeout = undefined;
                }
                this._loading = false;
                if (this._destroyed) return;

                const bgRes = this.loader.resources[this.backgroundName()];
                const gridRes = this.loader.resources.grid;

                if (!bgRes || bgRes.error || !gridRes || gridRes.error) {
                    console.error('[Surface] Texture load failed.',
                        'bg:', bgRes ? (bgRes.error || 'ok') : 'missing',
                        'grid:', gridRes ? (gridRes.error || 'ok') : 'missing');
                    this.destroyPixi();
                    this.retryInit();
                    return;
                }

                const bgTex = bgRes.texture;
                if (!bgTex || bgTex.width < 1 || bgTex.height < 1) {
                    console.error('[Surface] Background texture unusable:',
                        bgTex ? `${bgTex.width}×${bgTex.height}` : 'null');
                    this.destroyPixi();
                    this.retryInit();
                    return;
                }

                try {
                    this.setup();
                    this.align();
                    this.animate();
                    this.ready = true;
                } catch (err) {
                    console.error('[Surface] Render error:', err);
                    // Let the CSS fallback show through.
                    this.ready = true;
                }
            });

            // Create stage / container / renderer synchronously.
            this.stage = new Container();
            this.container = new Container();
            this.container.interactive = true;
            this.container.scale.set(this.containerScale());
            this.container.on('mousedown', this.mouseDown);
            this.container.on('mousemove', this.mouseMove);
            this.container.on('mouseup', this.mouseUp);
            this.container.on('mouseupoutside', this.mouseUp);
            this.container.on('touchstart', this.mouseDown);
            this.container.on('touchmove', this.mouseMove);
            this.container.on('touchend', this.mouseUp);
            this.container.on('touchendoutside', this.mouseUp);
            this.stage.addChild(this.container);

            try {
                this.renderer = autoDetectRenderer({
                    height: this.rendererHeight(),
                    width: this.rendererWidth(),
                    view: this.$refs.canvas,
                    // Transparent renderer so the CSS background-image on
                    // .surface-viewport shines through until (and unless)
                    // the PixiJS background sprite covers it.
                    transparent: true
                });
            } catch (e) {
                console.error('[Surface] Renderer creation failed:', e);
                this.destroyPixi();
                this.retryInit();
                return;
            }

            window.addEventListener('resize', this.resize);
        },

        retryInit() {
            this.retryCount += 1;

            if (this.retryCount > this.maxRetries) {
                console.error(`[Surface] Max retries (${this.maxRetries}) exhausted.`);
                // Remove the spinner — the CSS fallback background is
                // already visible behind the semi-transparent overlay.
                this.ready = true;
                return;
            }

            const delay = Math.min(1000 * (2 ** (this.retryCount - 1)), 8000);

            setTimeout(() => {
                if (!this._destroyed && !this.stage && !this._loading && this.planet.resource_id) {
                    this.initPixi();
                }
            }, delay);
        },

        updatePixi() {
            if (this._loading) return;

            if (!this.loader || !this.loader.resources.grid || this.loader.resources.grid.error) {
                this.destroyPixi();
                this.initPixi();
                return;
            }

            const backgroundName = this.backgroundName();

            const safeSetup = () => {
                try { this.setup(); }
                catch (err) { console.error('[Surface] updatePixi error:', err); }
            };

            if (!this.loader.resources[backgroundName]) {
                this._loading = true;
                this.loader.add({ name: backgroundName, url: this.toLocalPath(this.background()), crossOrigin: '' });
                this.loader.load(() => {
                    this._loading = false;
                    if (!this._destroyed) safeSetup();
                });
            } else {
                safeSetup();
            }
        },

        destroyPixi() {
            this._loading = false;
            this.clearIntervals();

            if (this._loadTimeout) { clearTimeout(this._loadTimeout); this._loadTimeout = undefined; }
            if (this.animationFrame) { cancelAnimationFrame(this.animationFrame); this.animationFrame = undefined; }
            if (this.renderer) { this.renderer.destroy(); this.renderer = undefined; }
            if (this.container) { this.container.destroy(true, true, true); this.container = undefined; }
            if (this.stage) { this.stage.destroy(true, true, true); this.stage = undefined; }
            if (this.loader) { this.loader.destroy(); this.loader = undefined; }

            utils.destroyTextureCache();
            window.removeEventListener('resize', this.resize);
        },

        // ─────────────── PixiJS scene setup ───────────────────────
        setup() {
            this.clearIntervals();
            this.container.removeChildren();
            this.container.addChild(this.backgroundSprite());

            _.forEach(
                this.planet.grids, grid => this.container.addChild(this.gridSprite(grid))
            );
        },

        clearIntervals() {
            _.forEach(this.intervals, interval => clearInterval(interval));
            this.intervals = [];
        },

        resize() {
            if (!this.renderer) return;
            this.renderer.resize(this.rendererWidth(), this.rendererHeight());
            this.container.scale.set(this.containerScale());
            this.align();
        },

        align() {
            this.container.position.x = this.centerX();
            this.container.position.y = this.centerY();
        },

        animate() {
            if (this._destroyed || !this.renderer || !this.stage || !this.container) return;

            this.animationFrame = requestAnimationFrame(this.animate);

            const x = this.containerX();
            const y = this.containerY();

            if (this.container.position.x < x) this.container.position.x = x;
            if (this.container.position.y < y) this.container.position.y = y;
            if (this.container.position.x > 0) this.container.position.x = 0;
            if (this.container.position.y > 0) this.container.position.y = 0;

            this.renderer.render(this.stage);
        },

        // ─────────────── Input handling ───────────────────────────
        mouseDown(e) {
            const start = e.data.getLocalPosition(this.container.parent);
            this.dragStartX = start.x - this.container.position.x;
            this.dragStartY = start.y - this.container.position.y;
            this.isDragging = true;
            this.dragged = 0;
        },

        mouseMove(e) {
            if (!this.isDragging) return;
            const moved = e.data.getLocalPosition(this.container.parent);
            const positionX = this.container.position.x;
            const positionY = this.container.position.y;
            this.container.position.x = moved.x - this.dragStartX;
            this.container.position.y = moved.y - this.dragStartY;
            this.dragged += Math.abs(positionX - this.container.position.x);
            this.dragged += Math.abs(positionY - this.container.position.y);
        },

        mouseUp() { this.isDragging = false; },

        // ─────────────── Texture helpers ──────────────────────────
        background() {
            return this.backgroundTexture.replace('__resource__', this.planet.resource_id);
        },

        backgroundName() {
            return `background_${this.planet.resource_id}`;
        },

        backgroundSprite() {
            return new Sprite(this.loader.resources[this.backgroundName()].texture);
        },

        // ─────────────── Layout helpers ───────────────────────────
        rendererWidth() {
            const vpWidth = this.$viewport ? this.$viewport.width() : 0;
            return vpWidth > 1 ? vpWidth : this.width;
        },

        rendererHeight() {
            const vpHeight = this.$viewport ? this.$viewport.height() : 0;
            return vpHeight > 1 ? vpHeight : this.height;
        },

        centerX() { return this.containerX() / 2; },
        centerY() { return this.containerY() / 2; },
        containerX() { return this.renderer.width - this.container.width; },
        containerY() { return this.renderer.height - this.container.height; },

        containerScale() {
            const width = this.rendererWidth();
            const height = this.rendererHeight();

            let current = _.findLast(this.breakpoints, bp => bp.minWidth <= width);
            if (!current) return 1;
            if (current.maxHeight === false || current.maxHeight >= height) return current.ratio;

            current = _.findLast(this.breakpoints, bp => bp.maxHeight >= height);
            return !_.isUndefined(current) ? current.ratio : 1;
        },

        // ─────────────── Grid sprites ─────────────────────────────
        gridSprite(grid) {
            const sprite = new Sprite(this.gridTexture(grid));
            sprite.interactive = true;
            sprite.hitArea = Sprites.hitArea;
            sprite.x = this.gridX(grid);
            sprite.y = this.gridY(grid);
            sprite.on('mouseover', () => this.gridOver(sprite));
            sprite.on('mouseout', () => this.gridOut(sprite));
            sprite.on('click', () => this.gridClick(grid));
            sprite.on('tap', () => this.gridClick(grid));
            this.gridLevel(grid, sprite);
            this.gridRemaining(grid, sprite);
            return sprite;
        },

        gridTexture(grid) {
            let frame = Sprites.plain;
            try {
                if (grid.construction) {
                    frame = Sprites.constructions[grid.construction.building_id] || Sprites.plain;
                } else if (grid.type === 1) {
                    if (grid.building_id) {
                        const bs = Sprites.buildings[grid.building_id];
                        if (bs && typeof bs === 'object' && !bs.width) {
                            frame = bs[this.planet.resource_id] || Sprites.plain;
                        } else {
                            frame = bs || Sprites.plain;
                        }
                    } else {
                        frame = Sprites.resources[this.planet.resource_id] || Sprites.plain;
                    }
                } else if (grid.building_id) {
                    frame = Sprites.buildings[grid.building_id] || Sprites.plain;
                }
            } catch (e) {
                frame = Sprites.plain;
            }
            return new Texture(this.loader.resources.grid.texture, frame);
        },

        gridX(grid) { return (grid.x - grid.y + 4) * 162 + (this.width - 1608) / 2; },
        gridY(grid) { return (grid.x + grid.y) * 81 + (this.height - 888) / 2; },
        gridOver(sprite) { sprite.alpha = 0.6; },
        gridOut(sprite) { sprite.alpha = 1; },

        gridClick(grid) {
            if (this.dragged > this.clickTreshold) return;
            EventBus.$emit(grid.building_id ? 'building-click' : 'grid-click', grid);
        },

        gridLevel(grid, sprite) {
            if (!grid.level) return;
            const text = new Text(grid.level, this.textStyle);
            text.position.x = (sprite.width - text.width) / 2;
            text.position.y = sprite.height - 50;
            sprite.addChild(text);
        },

        gridRemaining(grid, sprite) {
            let remaining, textStyle;
            if (grid.construction) { ({ remaining } = grid.construction); ({ textStyle } = this); }
            else if (grid.training) { ({ remaining } = grid.training); textStyle = _.assignIn({}, this.textStyle, { fill: '#ebb237' }); }
            else if (grid.upgrade) { ({ remaining } = grid.upgrade); ({ textStyle } = this); }
            if (!remaining) return;

            const text = new Text(Filters.timer(remaining), textStyle);
            text.position.x = (sprite.width - text.width) / 2;
            text.position.y = (sprite.height - text.height) / 2;
            sprite.addChild(text);

            const interval = setInterval(() => {
                remaining -= 1;
                text.text = Filters.timer(remaining);
                if (!remaining) clearInterval(interval);
            }, 1000);
            this.intervals.push(interval);
        }
    }
};
</script>
