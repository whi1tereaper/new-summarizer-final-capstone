(function () {
    'use strict';

    const GRID_W = 96;
    const GRID_H = 40;
    const CHROMA = 0.005;

    const clamp = (value, min, max) => Math.min(max, Math.max(min, value));
    const warnOnce = (() => {
        let warned = false;
        return (message) => {
            if (!warned) {
                warned = true;
                console.warn(message);
            }
        };
    })();

    class MeshText {
        constructor(options = {}) {
            this.options = Object.assign({
                text: 'MESH',
                color: '#ffffff',
                fontFamily: 'Inter',
                fontSize: 160,
                fontWeight: 700,
                fontStyle: 'normal',
                colorSplit: true,
                customColors: ['#ff40c0', '#40ff80'],
                force: 18,
            }, options);

            this.target = options.target || options.element || null;
            this.element = null;
            this.canvas = null;
            this.fallback = null;
            this.gl = null;
            this.program = null;
            this.texture = null;
            this.displacementBuffer = null;
            this.positionBuffer = null;
            this.uvBuffer = null;
            this.indexBuffer = null;
            this.animationFrame = null;
            this.resizeObserver = null;
            this.textCanvas = document.createElement('canvas');
            this.textContext = this.textCanvas.getContext('2d');
            this.pointer = {
                x: 0,
                y: 0,
                previousX: 0,
                previousY: 0,
                velocityX: 0,
                velocityY: 0,
                inside: false,
            };

            this.positions = new Float32Array(GRID_W * GRID_H * 2);
            this.uvs = new Float32Array(GRID_W * GRID_H * 2);
            this.displacements = new Float32Array(GRID_W * GRID_H * 2);
            this.velocityX = new Float32Array(GRID_W * GRID_H);
            this.velocityY = new Float32Array(GRID_W * GRID_H);
            this.indices = new Uint16Array((GRID_W - 1) * (GRID_H - 1) * 6);
            this.uniforms = null;
            this.reducedMotionQuery = null;
            this.destroyed = false;

            this.init();
            return this;
        }

        init() {
            const root = this.target || document.querySelector('.mesh-text[data-text]');
            if (!root) {
                return this;
            }

            this.element = root;
            this.element.classList.add('mesh-text');
            this.element.setAttribute('data-mesh-ready', 'true');
            this.element.setAttribute('aria-label', this.options.text || this.element.dataset.text || 'LIGHT');
            this.element.textContent = '';
            this.element.style.color = 'transparent';
            this.element.style.textShadow = 'none';

            const accessibleText = document.createElement('span');
            accessibleText.className = 'mesh-text__accessible';
            accessibleText.textContent = this.options.text;
            accessibleText.setAttribute('aria-hidden', 'true');
            this.element.appendChild(accessibleText);

            this.canvas = document.createElement('canvas');
            this.canvas.className = 'mesh-text__canvas';
            this.canvas.setAttribute('aria-hidden', 'true');
            this.element.appendChild(this.canvas);

            this.fallback = document.createElement('div');
            this.fallback.className = 'mesh-text__fallback';
            this.fallback.textContent = this.options.text;
            this.fallback.setAttribute('aria-hidden', 'true');
            this.fallback.style.display = 'none';
            this.element.appendChild(this.fallback);

            this.createMeshGeometry();
            this.bindPointerEvents();
            this.setupReducedMotion();
            this.setupWebGL();
            this.resize();
            this.setupResizeObserver();

            return this;
        }

        setupReducedMotion() {
            if (!window.matchMedia) {
                return;
            }

            this.reducedMotionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
            this.element.classList.toggle('mesh-text--reduced-motion', this.reducedMotionQuery.matches);

            const updateReducedMotion = (event) => {
                this.element.classList.toggle('mesh-text--reduced-motion', event.matches);
            };

            if (typeof this.reducedMotionQuery.addEventListener === 'function') {
                this.reducedMotionQuery.addEventListener('change', updateReducedMotion);
            } else if (typeof this.reducedMotionQuery.addListener === 'function') {
                this.reducedMotionQuery.addListener(updateReducedMotion);
            }
        }

        bindPointerEvents() {
            this.onPointerMove = (event) => {
                const rect = this.canvas.getBoundingClientRect();
                const x = ((event.clientX - rect.left) / rect.width) * 2 - 1;
                const y = 1 - ((event.clientY - rect.top) / rect.height) * 2;
                this.pointer.previousX = this.pointer.x;
                this.pointer.previousY = this.pointer.y;
                this.pointer.x = clamp(x, -1, 1);
                this.pointer.y = clamp(y, -1, 1);
                this.pointer.velocityX = this.pointer.x - this.pointer.previousX;
                this.pointer.velocityY = this.pointer.y - this.pointer.previousY;
                this.pointer.inside = true;
            };

            this.onPointerEnter = () => {
                this.pointer.inside = true;
            };

            this.onPointerLeave = () => {
                this.pointer.inside = false;
            };

            this.element.addEventListener('pointermove', this.onPointerMove);
            this.element.addEventListener('pointerenter', this.onPointerEnter);
            this.element.addEventListener('pointerleave', this.onPointerLeave);
            this.element.addEventListener('pointerdown', this.onPointerMove);
            this.element.addEventListener('pointercancel', this.onPointerLeave);
        }

        createMeshGeometry() {
            for (let row = 0; row < GRID_H; row += 1) {
                for (let col = 0; col < GRID_W; col += 1) {
                    const posIndex = (row * GRID_W + col) * 2;
                    const x = (col / (GRID_W - 1)) * 2 - 1;
                    const y = 1 - (row / (GRID_H - 1)) * 2;
                    this.positions[posIndex] = x;
                    this.positions[posIndex + 1] = y;
                    this.uvs[posIndex] = col / (GRID_W - 1);
                    this.uvs[posIndex + 1] = 1 - row / (GRID_H - 1);
                    this.displacements[posIndex] = 0;
                    this.displacements[posIndex + 1] = 0;
                }
            }

            let triIndex = 0;
            for (let row = 0; row < GRID_H - 1; row += 1) {
                for (let col = 0; col < GRID_W - 1; col += 1) {
                    const a = row * GRID_W + col;
                    const b = a + 1;
                    const c = a + GRID_W;
                    const d = c + 1;
                    this.indices[triIndex] = a;
                    this.indices[triIndex + 1] = c;
                    this.indices[triIndex + 2] = b;
                    this.indices[triIndex + 3] = b;
                    this.indices[triIndex + 4] = c;
                    this.indices[triIndex + 5] = d;
                    triIndex += 6;
                }
            }
        }

        setupWebGL() {
            const gl = this.canvas.getContext('webgl2', {
                alpha: true,
                antialias: true,
                depth: false,
                stencil: false,
                premultipliedAlpha: false,
                preserveDrawingBuffer: false,
            });

            if (!gl) {
                this.canvas.style.display = 'none';
                this.fallback.style.display = 'flex';
                warnOnce('MeshText: WebGL2 unavailable; using fallback renderer.');
                return;
            }

            this.gl = gl;
            const vertexSource = `#version 300 es
                in vec2 a_position;
                in vec2 a_uv;
                in vec2 a_displacement;
                out vec2 v_uv;
                out float v_disp;

                void main() {
                    vec2 displaced = a_position + a_displacement;
                    gl_Position = vec4(displaced, 0.0, 1.0);
                    v_uv = a_uv;
                    v_disp = length(a_displacement);
                }
            `;

            const fragmentSource = `#version 300 es
                precision mediump float;
                in vec2 v_uv;
                in float v_disp;
                out vec4 outColor;

                uniform sampler2D u_texture;
                uniform vec3 u_colorA;
                uniform vec3 u_colorB;
                uniform float u_chroma;

                void main() {
                    vec2 offset = vec2(u_chroma * (v_disp * 24.0 + 0.6), 0.0);
                    vec2 uvBase = v_uv;
                    vec2 uvR = uvBase + offset;
                    vec2 uvG = uvBase - offset * vec2(0.7, 1.0);

                    vec4 base = texture(u_texture, uvBase);
                    vec4 red = texture(u_texture, uvR);
                    vec4 green = texture(u_texture, uvG);
                    float alpha = base.a;
                    float fringe = clamp(v_disp * 2.5, 0.0, 1.0);
                    vec3 glow = mix(u_colorA, u_colorB, fringe);
                    vec3 rgb = vec3(red.r, green.g, base.b);
                    vec3 finalRGB = glow * (0.35 + 0.65 * alpha) + rgb * 0.9 * alpha;
                    outColor = vec4(finalRGB, alpha);
                }
            `;

            const vertexShader = this.compileShader(gl, gl.VERTEX_SHADER, vertexSource);
            const fragmentShader = this.compileShader(gl, gl.FRAGMENT_SHADER, fragmentSource);
            if (!vertexShader || !fragmentShader) {
                this.cleanupWebGL();
                return;
            }

            this.program = gl.createProgram();
            gl.attachShader(this.program, vertexShader);
            gl.attachShader(this.program, fragmentShader);
            gl.linkProgram(this.program);

            if (!gl.getProgramParameter(this.program, gl.LINK_STATUS)) {
                console.error('MeshText: WebGL program failed to link', gl.getProgramInfoLog(this.program));
                this.cleanupWebGL();
                return;
            }

            this.positionBuffer = gl.createBuffer();
            this.uvBuffer = gl.createBuffer();
            this.displacementBuffer = gl.createBuffer();
            this.indexBuffer = gl.createBuffer();

            gl.bindBuffer(gl.ARRAY_BUFFER, this.positionBuffer);
            gl.bufferData(gl.ARRAY_BUFFER, this.positions, gl.DYNAMIC_DRAW);

            gl.bindBuffer(gl.ARRAY_BUFFER, this.uvBuffer);
            gl.bufferData(gl.ARRAY_BUFFER, this.uvs, gl.STATIC_DRAW);

            gl.bindBuffer(gl.ARRAY_BUFFER, this.displacementBuffer);
            gl.bufferData(gl.ARRAY_BUFFER, this.displacements, gl.DYNAMIC_DRAW);

            gl.bindBuffer(gl.ELEMENT_ARRAY_BUFFER, this.indexBuffer);
            gl.bufferData(gl.ELEMENT_ARRAY_BUFFER, this.indices, gl.STATIC_DRAW);

            this.uniforms = {
                texture: gl.getUniformLocation(this.program, 'u_texture'),
                colorA: gl.getUniformLocation(this.program, 'u_colorA'),
                colorB: gl.getUniformLocation(this.program, 'u_colorB'),
                chroma: gl.getUniformLocation(this.program, 'u_chroma'),
            };

            const positionLocation = gl.getAttribLocation(this.program, 'a_position');
            const uvLocation = gl.getAttribLocation(this.program, 'a_uv');
            const displacementLocation = gl.getAttribLocation(this.program, 'a_displacement');

            gl.bindBuffer(gl.ARRAY_BUFFER, this.positionBuffer);
            gl.enableVertexAttribArray(positionLocation);
            gl.vertexAttribPointer(positionLocation, 2, gl.FLOAT, false, 0, 0);

            gl.bindBuffer(gl.ARRAY_BUFFER, this.uvBuffer);
            gl.enableVertexAttribArray(uvLocation);
            gl.vertexAttribPointer(uvLocation, 2, gl.FLOAT, false, 0, 0);

            gl.bindBuffer(gl.ARRAY_BUFFER, this.displacementBuffer);
            gl.enableVertexAttribArray(displacementLocation);
            gl.vertexAttribPointer(displacementLocation, 2, gl.FLOAT, false, 0, 0);

            this.texture = gl.createTexture();
            gl.bindTexture(gl.TEXTURE_2D, this.texture);
            gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR);
            gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR);
            gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE);
            gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);

            this.createTextTexture();
            this.startLoop();
        }

        compileShader(gl, type, source) {
            const shader = gl.createShader(type);
            gl.shaderSource(shader, source);
            gl.compileShader(shader);
            if (!gl.getShaderParameter(shader, gl.COMPILE_STATUS)) {
                console.error('MeshText shader error:', gl.getShaderInfoLog(shader));
                gl.deleteShader(shader);
                return null;
            }
            return shader;
        }

        createTextTexture() {
            if (!this.textContext) {
                return;
            }

            const rect = this.element.getBoundingClientRect();
            const text = this.options.text || 'MESH';
            const family = this.options.fontFamily || 'Inter';
            const style = this.options.fontStyle || 'normal';
            const weight = this.options.fontWeight || 700;

            this.textCanvas.width = Math.max(1, Math.round(rect.width * 2));
            this.textCanvas.height = Math.max(1, Math.round(rect.height * 2));

            const ctx = this.textContext;
            ctx.clearRect(0, 0, this.textCanvas.width, this.textCanvas.height);
            const isBrand = this.element.classList.contains('mesh-text--brand');
            ctx.textAlign = isBrand ? 'left' : 'center';
            ctx.textBaseline = 'middle';
            ctx.fillStyle = this.options.color || '#ffffff';
            const fittedFontSize = Math.max(1, Math.min(360, rect.height * 1.18));
            ctx.font = `${style} ${weight} ${fittedFontSize}px ${family}`;
            if ('letterSpacing' in ctx) {
                ctx.letterSpacing = `${Math.max(0, fittedFontSize * 0.025)}px`;
            }
            if (isBrand) {
                const measuredWidth = ctx.measureText(text).width;
                const horizontalScale = measuredWidth > 0
                    ? this.textCanvas.width / measuredWidth
                    : 1;
                ctx.save();
                ctx.scale(horizontalScale, 1);
                ctx.fillText(text, 0, this.textCanvas.height / 2);
                ctx.restore();
            } else {
                ctx.fillText(text, this.textCanvas.width / 2, this.textCanvas.height / 2);
            }

            if (document.fonts && document.fonts.load) {
                document.fonts.load(`${style} ${weight} ${fittedFontSize}px ${family}`).catch(() => {});
            }

            if (this.gl && this.texture) {
                const gl = this.gl;
                gl.bindTexture(gl.TEXTURE_2D, this.texture);
                gl.pixelStorei(gl.UNPACK_FLIP_Y_WEBGL, true);
                gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, gl.RGBA, gl.UNSIGNED_BYTE, this.textCanvas);
            }
        }

        startLoop() {
            if (!this.gl || this.animationFrame) {
                return;
            }

            const tick = () => {
                if (this.destroyed) {
                    return;
                }
                this.updateMesh();
                this.render();
                this.animationFrame = window.requestAnimationFrame(tick);
            };

            this.animationFrame = window.requestAnimationFrame(tick);
        }

        updateMesh() {
            const reducedMotion = this.reducedMotionQuery && this.reducedMotionQuery.matches;
            const force = reducedMotion ? 4 : (this.options.force || 18);

            for (let row = 0; row < GRID_H; row += 1) {
                for (let col = 0; col < GRID_W; col += 1) {
                    const index = row * GRID_W + col;
                    const posIndex = index * 2;
                    const baseX = this.positions[posIndex];
                    const baseY = this.positions[posIndex + 1];
                    const dx = this.pointer.x - (baseX + this.displacements[posIndex]);
                    const dy = this.pointer.y - (baseY + this.displacements[posIndex + 1]);
                    const distance = Math.sqrt(dx * dx + dy * dy);
                    const proximity = Math.max(0, 1 / (1 + distance / 0.05) - 0.1);

                    if (this.pointer.inside) {
                        this.velocityX[index] += this.pointer.velocityX * force * proximity;
                        this.velocityY[index] += this.pointer.velocityY * force * proximity;
                    }

                    this.velocityX[index] -= this.displacements[posIndex] * 0.08;
                    this.velocityY[index] -= this.displacements[posIndex + 1] * 0.08;
                    this.velocityX[index] *= 0.9;
                    this.velocityY[index] *= 0.9;

                    this.displacements[posIndex] += this.velocityX[index] * 0.1;
                    this.displacements[posIndex + 1] += this.velocityY[index] * 0.1;
                    this.displacements[posIndex] = clamp(this.displacements[posIndex], -1, 1);
                    this.displacements[posIndex + 1] = clamp(this.displacements[posIndex + 1], -1, 1);
                }
            }

            if (this.gl && this.displacementBuffer) {
                this.gl.bindBuffer(this.gl.ARRAY_BUFFER, this.displacementBuffer);
                this.gl.bufferSubData(this.gl.ARRAY_BUFFER, 0, this.displacements);
            }
        }

        render() {
            const gl = this.gl;
            if (!gl || !this.program) {
                this.canvas.style.display = 'none';
                this.fallback.style.display = 'flex';
                return;
            }

            this.canvas.style.display = 'block';
            this.fallback.style.display = 'none';

            gl.viewport(0, 0, this.canvas.width, this.canvas.height);
            gl.clearColor(0, 0, 0, 0);
            gl.clear(gl.COLOR_BUFFER_BIT);
            gl.useProgram(this.program);

            gl.activeTexture(gl.TEXTURE0);
            gl.bindTexture(gl.TEXTURE_2D, this.texture);
            gl.uniform1i(this.uniforms.texture, 0);

            const colors = Array.isArray(this.options.customColors) && this.options.customColors.length >= 2
                ? this.options.customColors
                : ['#ff40c0', '#40ff80'];
            gl.uniform3fv(this.uniforms.colorA, hexToRgb(colors[0]));
            gl.uniform3fv(this.uniforms.colorB, hexToRgb(colors[1]));
            gl.uniform1f(this.uniforms.chroma, this.options.colorSplit === false ? 0 : CHROMA);

            gl.bindBuffer(gl.ELEMENT_ARRAY_BUFFER, this.indexBuffer);
            gl.drawElements(gl.TRIANGLES, this.indices.length, gl.UNSIGNED_SHORT, 0);
        }

        resize() {
            if (!this.element || !this.canvas) {
                return;
            }

            const rect = this.element.getBoundingClientRect();
            const width = Math.max(rect.width || 320, 240);
            const height = Math.max(rect.height || 80, 40);
            const ratio = Math.min(window.devicePixelRatio || 1, 2);

            this.canvas.width = Math.max(1, Math.round(width * ratio));
            this.canvas.height = Math.max(1, Math.round(height * ratio));
            this.canvas.style.width = `${width}px`;
            this.canvas.style.height = `${height}px`;

            if (this.gl) {
                this.createTextTexture();
            }
        }

        setupResizeObserver() {
            if (!('ResizeObserver' in window) || this.resizeObserver) {
                return;
            }

            this.resizeObserver = new ResizeObserver(() => {
                this.resize();
            });
            this.resizeObserver.observe(this.element);
        }

        cleanupWebGL() {
            if (!this.gl) {
                return;
            }

            const gl = this.gl;
            if (this.program) {
                gl.deleteProgram(this.program);
                this.program = null;
            }
            if (this.texture) {
                gl.deleteTexture(this.texture);
                this.texture = null;
            }
            if (this.positionBuffer) {
                gl.deleteBuffer(this.positionBuffer);
                this.positionBuffer = null;
            }
            if (this.uvBuffer) {
                gl.deleteBuffer(this.uvBuffer);
                this.uvBuffer = null;
            }
            if (this.displacementBuffer) {
                gl.deleteBuffer(this.displacementBuffer);
                this.displacementBuffer = null;
            }
            if (this.indexBuffer) {
                gl.deleteBuffer(this.indexBuffer);
                this.indexBuffer = null;
            }

            this.gl = null;
        }

        destroy() {
            this.destroyed = true;
            if (this.animationFrame) {
                window.cancelAnimationFrame(this.animationFrame);
                this.animationFrame = null;
            }
            if (this.resizeObserver) {
                this.resizeObserver.disconnect();
                this.resizeObserver = null;
            }

            this.element.removeEventListener('pointermove', this.onPointerMove);
            this.element.removeEventListener('pointerenter', this.onPointerEnter);
            this.element.removeEventListener('pointerleave', this.onPointerLeave);
            this.element.removeEventListener('pointerdown', this.onPointerMove);
            this.element.removeEventListener('pointercancel', this.onPointerLeave);
            this.cleanupWebGL();
            this.element.innerHTML = '';
        }
    }

    function hexToRgb(hex) {
        const normalized = hex.replace('#', '');
        const value = normalized.length === 3
            ? normalized.split('').map((char) => char + char).join('')
            : normalized;
        const numeric = Number.parseInt(value, 16);
        return new Float32Array([
            ((numeric >> 16) & 255) / 255,
            ((numeric >> 8) & 255) / 255,
            (numeric & 255) / 255,
        ]);
    }

    function initializeMeshText() {
        const targets = Array.from(document.querySelectorAll('.mesh-text[data-text]')).filter((node) => {
            const text = (node.dataset.text || '').trim();
            const brandName = (node.dataset.brand || '').trim();
            const isFooterBrand = Boolean(node.closest('.site-footer'));
            return isFooterBrand && (brandName.toLowerCase() === 'light' || text.toUpperCase() === 'LIGHT');
        });

        targets.forEach((node) => {
            if (node.__meshText) {
                return;
            }

            const options = {
                target: node,
                text: node.dataset.text || node.textContent.trim() || 'MESH',
                color: node.dataset.color || '#ffffff',
                fontFamily: node.dataset.fontFamily || 'Inter',
                fontSize: Number(node.dataset.fontSize || 160),
                fontWeight: Number(node.dataset.fontWeight || 700),
                fontStyle: node.dataset.fontStyle || 'normal',
                customColors: node.dataset.customColors ? node.dataset.customColors.split(',') : ['#ff40c0', '#40ff80'],
                force: Number(node.dataset.force || 18),
            };

            node.__meshText = new MeshText(options);
        });
    }

    window.MeshText = MeshText;
    window.MeshTextInitializer = initializeMeshText;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeMeshText, { once: true });
    } else {
        initializeMeshText();
    }
})();
