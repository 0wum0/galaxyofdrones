<template>
    <div class="surface-viewport" ref="viewport">
        <canvas ref="canvas" class="surface"></canvas>
        <div v-if="!ready" class="surface-loading">
            <div class="surface-spinner"></div>
            <p v-if="errorMessage" class="surface-error-text">
                {{ errorMessage }}
            </p>
        </div>
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
        this.$viewport = $(this.$el);

        EventBus.$on('planet-updated', this.planetUpdated);

        // Strategy 1: Request cached data from Sidebar synchronously.
        EventBus.$emit('planet-data-request');

        // Strategy 2: Direct API fetch after 1.5 s.
        this._apiTimer = setTimeout(() => {
            if (!this._destroyed && !this.stage && !this._loading) {
                this.fetchPlanetDirect();
            }
        }, 1500);

        // Strategy 3: Final EventBus retry after 4 s.
        this._retryTimer = setTimeout(() => {
            if (!this._destroyed && !this.stage && !this._loading) {
                EventBus.$emit('planet-data-request');
            }
        }, 4000);
    },

    beforeDestroy() {
        this._destroyed = true;

        EventBus.$off('planet-updated', this.planetUpdated);

        if (this._apiTimer) {
            clearTimeout(this._apiTimer);
            this._apiTimer = undefined;
        }

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
        /**
         * Convert an absolute URL to a root-relative path.
         *
         * The PixiJS resource-loader adds crossOrigin='anonymous' to any
         * URL it considers cross-origin.  If APP_URL uses a different
         * protocol (http vs https) or a slightly different host than the
         * actual page, the loader treats same-server images as cross-
         * origin.  The browser then makes a CORS request; if the server
         * doesn't reply with Access-Control-Allow-Origin the image either
         * fails to load or loads as a "tainted" texture that WebGL cannot
         * use — resulting in black/invisible sprites.
         *
         * Stripping the origin and keeping only the pathname + search
         * guarantees same-origin treatment, so crossOrigin stays empty
         * and WebGL can use the texture without any CORS requirement.
         */
        toLocalPath(url) {
            if (!url) return url;

            try {
                const parsed = new URL(url, window.location.origin);
                return parsed.pathname + parsed.search;
            } catch (e) {
                // If URL parsing fails, try simple regex strip
                return url.replace(/^https?:\/\/[^/]+/, '');
            }
        },

        /**
         * Set a CSS background-image on the viewport element as a visual
         * fallback.  Even if PixiJS rendering fails completely the user
         * sees the planet image instead of a solid black screen.
         */
        applyCssBackground() {
            if (!this.planet.resource_id || !this.$refs.viewport) return;

            const url = this.toLocalPath(this.background());
            const el = this.$refs.viewport;

            el.style.backgroundImage = `url(${url})`;
            el.style.backgroundSize = 'cover';
            el.style.backgroundPosition = 'center';
        },

        fetchPlanetDirect() {
            axios.get('/api/planet').then(response => {
                if (!this._destroyed && response.data && response.data.id) {
                    this.planetUpdated(response.data);
                }
            }).catch(err => {
                console.error('[Surface] Direct API fetch failed:', err);
                if (!this._destroyed && !this.stage) {
                    this.errorMessage = 'Failed to load planet data. Please reload.';
                }
            });
        },

        planetUpdated(planet) {
            if (this._destroyed) {
                return;
            }

            this.planet = planet;

            // Apply CSS fallback background immediately — visible while
            // PixiJS loads and also if WebGL rendering fails entirely.
            this.applyCssBackground();

            if (!this.stage && !this._loading) {
                this.$viewport = $(this.$el);
                this.initPixi();
            } else if (this.stage) {
                this.updatePixi();
            }
        },

        initPixi() {
            if (this._destroyed) return;

            this._loading = true;
            this.errorMessage = '';

            this.loader = new Loader();

            // Log + continue on per-resource errors.
            this.loader.onError.add((error, _loader, resource) => {
                console.error('[Surface] Loader error for', resource.url, error);
            });

            // Convert URLs to root-relative paths to avoid cross-origin /
            // mixed-content issues with the PixiJS resource-loader.
            const bgUrl = this.toLocalPath(this.background());
            const gridUrl = this.toLocalPath(this.gridTextureAtlas);

            // Add resources with explicit crossOrigin='' to prevent the
            // loader from adding 'anonymous' (which triggers CORS).
            this.loader.add({
                name: this.backgroundName(),
                url: bgUrl,
                crossOrigin: ''
            });
            this.loader.add({
                name: 'grid',
                url: gridUrl,
                crossOrigin: ''
            });

            // Safety timeout: 15 s.
            this._loadTimeout = setTimeout(() => {
                if (this._loading && !this._destroyed) {
                    console.error('[Surface] Texture loading timed out — retrying.');
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

                const bgResource = this.loader.resources[this.backgroundName()];
                const gridResource = this.loader.resources.grid;

                if (!bgResource || bgResource.error || !gridResource || gridResource.error) {
                    console.error('[Surface] Critical texture(s) failed to load —',
                        'bg:', bgResource ? (bgResource.error || 'ok') : 'missing',
                        'grid:', gridResource ? (gridResource.error || 'ok') : 'missing');
                    this.destroyPixi();
                    this.retryInit();
                    return;
                }

                // Verify the textures have real dimensions. A 0×0 texture
                // means the image loaded but is unusable (tainted, corrupt,
                // or WebGL rejected it).
                const bgTex = bgResource.texture;
                const gridTex = gridResource.texture;

                if (!bgTex || bgTex.width < 1 || bgTex.height < 1) {
                    console.error('[Surface] Background texture has no dimensions:',
                        bgTex ? `${bgTex.width}×${bgTex.height}` : 'null');
                    this.destroyPixi();
                    this.retryInit();
                    return;
                }

                if (!gridTex || gridTex.width < 1 || gridTex.height < 1) {
                    console.error('[Surface] Grid texture has no dimensions:',
                        gridTex ? `${gridTex.width}×${gridTex.height}` : 'null');
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
                    console.error('[Surface] Render setup error:', err);
                    this.ready = true;
                    this.errorMessage = 'Surface render error. Please reload.';
                }
            });

            // Create stage / container / renderer synchronously (before
            // the async loader callback can fire).
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
                    backgroundColor: 0x0b0e14,
                    view: this.$refs.canvas,
                    width: this.rendererWidth()
                });
            } catch (e) {
                console.error('[Surface] Failed to create PixiJS renderer:', e);
                this.destroyPixi();
                this.retryInit();
                return;
            }

            window.addEventListener('resize', this.resize);
        },

        retryInit() {
            this.retryCount += 1;

            if (this.retryCount > this.maxRetries) {
                console.error(`[Surface] Max retries reached (${this.maxRetries}).`);
                // Remove the loading overlay so the CSS fallback background
                // is visible instead of an endless spinner.
                this.ready = true;
                this.errorMessage = 'Surface could not be loaded. Please reload the page.';
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
            if (this._loading) {
                return;
            }

            if (!this.loader || !this.loader.resources.grid || this.loader.resources.grid.error) {
                this.destroyPixi();
                this.initPixi();
                return;
            }

            const backgroundName = this.backgroundName();

            const safeSetup = () => {
                try {
                    this.setup();
                } catch (err) {
                    console.error('[Surface] updatePixi setup error:', err);
                }
            };

            if (!this.loader.resources[backgroundName]) {
                this._loading = true;
                const bgUrl = this.toLocalPath(this.background());
                this.loader.add({
                    name: backgroundName,
                    url: bgUrl,
                    crossOrigin: ''
                });
                this.loader.load(() => {
                    this._loading = false;
                    if (!this._destroyed) {
                        safeSetup();
                    }
                });
            } else {
                safeSetup();
            }
        },

        destroyPixi() {
            this._loading = false;
            this.clearIntervals();

            if (this._loadTimeout) {
                clearTimeout(this._loadTimeout);
                this._loadTimeout = undefined;
            }

            if (this.animationFrame) {
                cancelAnimationFrame(this.animationFrame);
                this.animationFrame = undefined;
            }

            if (this.renderer) {
                this.renderer.destroy();
                this.renderer = undefined;
            }

            if (this.container) {
                this.container.destroy(true, true, true);
                this.container = undefined;
            }

            if (this.stage) {
                this.stage.destroy(true, true, true);
                this.stage = undefined;
            }

            if (this.loader) {
                this.loader.destroy();
                this.loader = undefined;
            }

            utils.destroyTextureCache();

            window.removeEventListener('resize', this.resize);
        },

        setup() {
            this.clearIntervals();

            this.container.removeChildren();
            this.container.addChild(this.backgroundSprite());

            _.forEach(
                this.planet.grids, grid => this.container.addChild(this.gridSprite(grid))
            );
        },

        clearIntervals() {
            _.forEach(
                this.intervals, interval => clearInterval(interval)
            );

            this.intervals = [];
        },

        resize() {
            if (!this.renderer) {
                return;
            }

            this.renderer.resize(this.rendererWidth(), this.rendererHeight());
            this.container.scale.set(this.containerScale());
            this.align();
        },

        align() {
            this.container.position.x = this.centerX();
            this.container.position.y = this.centerY();
        },

        animate() {
            if (this._destroyed || !this.renderer || !this.stage || !this.container) {
                return;
            }

            this.animationFrame = requestAnimationFrame(this.animate);

            const x = this.containerX();
            const y = this.containerY();

            if (this.container.position.x < x) {
                this.container.position.x = x;
            }

            if (this.container.position.y < y) {
                this.container.position.y = y;
            }

            if (this.container.position.x > 0) {
                this.container.position.x = 0;
            }

            if (this.container.position.y > 0) {
                this.container.position.y = 0;
            }

            this.renderer.render(this.stage);
        },

        mouseDown(e) {
            const start = e.data.getLocalPosition(this.container.parent);

            this.dragStartX = start.x - this.container.position.x;
            this.dragStartY = start.y - this.container.position.y;

            this.isDragging = true;
            this.dragged = 0;
        },

        mouseMove(e) {
            if (this.isDragging) {
                const moved = e.data.getLocalPosition(this.container.parent);

                const positionX = this.container.position.x;
                const positionY = this.container.position.y;

                this.container.position.x = moved.x - this.dragStartX;
                this.container.position.y = moved.y - this.dragStartY;

                this.dragged += Math.abs(positionX - this.container.position.x);
                this.dragged += Math.abs(positionY - this.container.position.y);
            }
        },

        mouseUp() {
            this.isDragging = false;
        },

        background() {
            return this.backgroundTexture.replace('__resource__', this.planet.resource_id);
        },

        backgroundName() {
            return `background_${this.planet.resource_id}`;
        },

        backgroundSprite() {
            return new Sprite(this.loader.resources[this.backgroundName()].texture);
        },

        rendererWidth() {
            const vpWidth = this.$viewport ? this.$viewport.width() : 0;
            return vpWidth > 1 ? vpWidth : this.width;
        },

        rendererHeight() {
            const vpHeight = this.$viewport ? this.$viewport.height() : 0;
            return vpHeight > 1 ? vpHeight : this.height;
        },

        centerX() {
            return this.containerX() / 2;
        },

        centerY() {
            return this.containerY() / 2;
        },

        containerX() {
            return this.renderer.width - this.container.width;
        },

        containerY() {
            return this.renderer.height - this.container.height;
        },

        containerScale() {
            const width = this.rendererWidth();
            const height = this.rendererHeight();

            let current = _.findLast(
                this.breakpoints, breakpoint => breakpoint.minWidth <= width
            );

            if (!current) {
                return 1;
            }

            if (current.maxHeight === false || current.maxHeight >= height) {
                return current.ratio;
            }

            current = _.findLast(
                this.breakpoints, breakpoint => breakpoint.maxHeight >= height
            );

            if (!_.isUndefined(current)) {
                return current.ratio;
            }

            return 1;
        },

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
                        const buildingSprite = Sprites.buildings[grid.building_id];

                        if (buildingSprite && typeof buildingSprite === 'object' && !buildingSprite.width) {
                            frame = buildingSprite[this.planet.resource_id] || Sprites.plain;
                        } else {
                            frame = buildingSprite || Sprites.plain;
                        }
                    } else {
                        frame = Sprites.resources[this.planet.resource_id] || Sprites.plain;
                    }
                } else if (grid.building_id) {
                    frame = Sprites.buildings[grid.building_id] || Sprites.plain;
                }
            } catch (e) {
                console.warn('[Surface] gridTexture fallback for grid', grid.id, e.message);
                frame = Sprites.plain;
            }

            return new Texture(this.loader.resources.grid.texture, frame);
        },

        gridX(grid) {
            return (grid.x - grid.y + 4) * 162 + (this.width - 1608) / 2;
        },

        gridY(grid) {
            return (grid.x + grid.y) * 81 + (this.height - 888) / 2;
        },

        gridOver(sprite) {
            sprite.alpha = 0.6;
        },

        gridOut(sprite) {
            sprite.alpha = 1;
        },

        gridClick(grid) {
            if (this.dragged > this.clickTreshold) {
                return;
            }

            EventBus.$emit(grid.building_id
                ? 'building-click'
                : 'grid-click', grid);
        },

        gridLevel(grid, sprite) {
            if (!grid.level) {
                return;
            }

            const text = new Text(grid.level, this.textStyle);

            text.position.x = (sprite.width - text.width) / 2;
            text.position.y = sprite.height - 50;

            sprite.addChild(text);
        },

        gridRemaining(grid, sprite) {
            let remaining;
            let textStyle;

            if (grid.construction) {
                ({ remaining } = grid.construction);
                ({ textStyle } = this);
            } else if (grid.training) {
                ({ remaining } = grid.training);
                textStyle = _.assignIn({}, this.textStyle, {
                    fill: '#ebb237'
                });
            } else if (grid.upgrade) {
                ({ remaining } = grid.upgrade);
                ({ textStyle } = this);
            }

            if (!remaining) {
                return;
            }

            const text = new Text(Filters.timer(remaining), textStyle);

            text.position.x = (sprite.width - text.width) / 2;
            text.position.y = (sprite.height - text.height) / 2;

            sprite.addChild(text);

            const interval = setInterval(() => {
                remaining -= 1;

                text.text = Filters.timer(remaining);

                if (!remaining) {
                    clearInterval(interval);
                }
            }, 1000);

            this.intervals.push(interval);
        }
    }
};
</script>
