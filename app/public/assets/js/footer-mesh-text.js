/**
 * footer-mesh-text.js
 * Scoped WebGL2 Mesh Text Hover Deformation ONLY for Footer Branding "LIGHT"
 *
 * Characteristics:
 * - 96 x 40 deformation grid
 * - GPU displacement with Spring & Damping physics
 * - Chromatic edge split during displacement; identical solid black (#080808) at rest
 * - Pointer events strictly isolated to the visible LIGHT canvas bounds
 * - Dynamic high-DPI canvas texture generation matching fallback typography
 * - Semantic & visual fallback to standard typography if WebGL2 is unavailable
 * - Respects prefers-reduced-motion: reduce
 */

(function () {
    'use strict';

    const GRID_W = 96;
    const GRID_H = 40;
    const SPRING_K = 0.08;
    const DAMPING = 0.9;
    const DT = 0.1;
    const CHROMA = 0.005;
    const DRAG = 1.8;
    const FORCE = 18;

    const clamp = (val, min, max) => Math.min(max, Math.max(min, val));

    function hexToRgb(hex) {
        const normalized = hex.replace('#', '');
        const val = normalized.length === 3
            ? normalized.split('').map((c) => c + c).join('')
            : normalized;
        const num = Number.parseInt(val, 16);
        return new Float32Array([
            ((num >> 16) & 255) / 255,
            ((num >> 8) & 255) / 255,
            (num & 255) / 255,
        ]);
    }

    class FooterMeshText {
        constructor(element) {
            if (!element) return;

            this.element = element;
            this.fallback = element.querySelector('.footer-light-fallback');
            this.canvas = element.querySelector('.footer-light-canvas');

            if (!this.canvas) {
                this.canvas = document.createElement('canvas');
                this.canvas.className = 'footer-light-canvas';
                this.canvas.setAttribute('aria-hidden', 'true');
                this.element.appendChild(this.canvas);
            }

            this.gl = null;
            this.program = null;
            this.texture = null;
            this.displacementBuffer = null;
            this.positionBuffer = null;
            this.uvBuffer = null;
            this.indexBuffer = null;
            this.animationFrame = null;
            this.resizeObserver = null;
            this.destroyed = false;

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

            this.colorA = hexToRgb('#7F00FF'); // Accent purple
            this.colorB = hexToRgb('#3ecfbf'); // Accent cyan

            this.init();
        }

        init() {
            this.createMeshGeometry();
            if (!this.setupWebGL()) {
                return; // Graceful fallback remains displayed if WebGL2 unsupported
            }

            this.bindPointerEvents();
            this.resize();
            this.setupResizeObserver();
            this.startLoop();

            // Font loading readiness: re-render texture when webfonts finish loading
            if (document.fonts && document.fonts.ready) {
                document.fonts.ready.then(() => {
                    if (!this.destroyed && this.gl) {
                        this.createTextTexture();
                        this.render();
                    }
                }).catch(() => {});
            }

            // Mark active: displays canvas, hides fallback text visually without layout jump
            this.element.classList.add('is-active');
            this.render();
        }

        bindPointerEvents() {
            this.onPointerMove = (event) => {
                const rect = this.canvas.getBoundingClientRect();
                if (rect.width <= 0 || rect.height <= 0) return;
                const x = ((event.clientX - rect.left) / rect.width) * 2 - 1;
                const y = 1 - ((event.clientY - rect.top) / rect.height) * 2;
                this.pointer.previousX = this.pointer.x;
                this.pointer.previousY = this.pointer.y;
                this.pointer.x = clamp(x, -1, 1);
                this.pointer.y = clamp(y, -1, 1);
                this.pointer.velocityX = (this.pointer.x - this.pointer.previousX) * DRAG;
                this.pointer.velocityY = (this.pointer.y - this.pointer.previousY) * DRAG;
                this.pointer.inside = true;
            };

            this.onPointerEnter = (event) => {
                this.pointer.inside = true;
                const rect = this.canvas.getBoundingClientRect();
                if (rect.width > 0 && rect.height > 0) {
                    const x = ((event.clientX - rect.left) / rect.width) * 2 - 1;
                    const y = 1 - ((event.clientY - rect.top) / rect.height) * 2;
                    this.pointer.x = clamp(x, -1, 1);
                    this.pointer.y = clamp(y, -1, 1);
                    this.pointer.previousX = this.pointer.x;
                    this.pointer.previousY = this.pointer.y;
                }
                this.pointer.velocityX = 0;
                this.pointer.velocityY = 0;
            };

            this.onPointerLeave = () => {
                this.pointer.inside = false;
                this.pointer.velocityX = 0;
                this.pointer.velocityY = 0;
            };

            // Attached ONLY to this.canvas and this.element (#footer-light-mesh)
            const targets = [this.canvas, this.element];
            targets.forEach((target) => {
                target.addEventListener('pointerenter', this.onPointerEnter);
                target.addEventListener('pointermove', this.onPointerMove);
                target.addEventListener('pointerleave', this.onPointerLeave);
                target.addEventListener('pointercancel', this.onPointerLeave);
            });
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
                return false;
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
`.trim();

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
    float fringe = clamp(v_disp * 4.0, 0.0, 1.0);
    vec2 offset = vec2(u_chroma * (v_disp * 35.0), 0.0);

    vec4 base = texture(u_texture, v_uv);
    vec4 red = texture(u_texture, v_uv + offset);
    vec4 green = texture(u_texture, v_uv - offset * 0.75);

    float alpha = max(base.a, max(red.a, green.a));
    if (alpha < 0.01) {
        discard;
    }

    vec3 splitColor = mix(u_colorA, u_colorB, clamp(v_disp * 2.5, 0.0, 1.0));
    float channelSplit = clamp(abs(red.a - green.a) * 1.5 + abs(base.a - red.a), 0.0, 1.0);
    vec3 textColor = base.rgb;
    vec3 finalRGB = mix(textColor, splitColor, clamp(fringe * (channelSplit * 0.9 + 0.35), 0.0, 1.0));

    outColor = vec4(finalRGB, alpha);
}
`.trim();

            const vertexShader = this.compileShader(gl, gl.VERTEX_SHADER, vertexSource);
            const fragmentShader = this.compileShader(gl, gl.FRAGMENT_SHADER, fragmentSource);
            if (!vertexShader || !fragmentShader) {
                this.cleanupWebGL();
                return false;
            }

            this.program = gl.createProgram();
            gl.attachShader(this.program, vertexShader);
            gl.attachShader(this.program, fragmentShader);
            gl.linkProgram(this.program);

            if (!gl.getProgramParameter(this.program, gl.LINK_STATUS)) {
                console.error('FooterMeshText link error:', gl.getProgramInfoLog(this.program));
                this.cleanupWebGL();
                return false;
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

            return true;
        }

        compileShader(gl, type, source) {
            const shader = gl.createShader(type);
            gl.shaderSource(shader, source);
            gl.compileShader(shader);
            if (!gl.getShaderParameter(shader, gl.COMPILE_STATUS)) {
                console.error('FooterMeshText shader compile error:', gl.getShaderInfoLog(shader));
                gl.deleteShader(shader);
                return null;
            }
            return shader;
        }

        createTextTexture() {
            if (!this.textContext || !this.element || !this.canvas) return;

            const rect = this.canvas.getBoundingClientRect();
            const dpr = Math.min(window.devicePixelRatio || 1, 2);

            this.textCanvas.width = Math.max(1, Math.round(rect.width * dpr));
            this.textCanvas.height = Math.max(1, Math.round(rect.height * dpr));

            const ctx = this.textContext;
            ctx.clearRect(0, 0, this.textCanvas.width, this.textCanvas.height);

            const computed = this.fallback ? window.getComputedStyle(this.fallback) : null;
            const family = computed ? computed.fontFamily : "'Satoshi', 'Outfit', 'Inter', system-ui, sans-serif";
            const weight = computed ? computed.fontWeight : '900';
            const style = computed ? computed.fontStyle : 'normal';
            const rawFontSize = computed ? parseFloat(computed.fontSize) : 160;
            const color = computed ? computed.color : '#080808';

            const fittedFontSize = Math.max(1, Math.round(rawFontSize * dpr));
            ctx.font = `${style} ${weight} ${fittedFontSize}px ${family}`;
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillStyle = color;

            if ('letterSpacing' in ctx && computed) {
                const ls = computed.letterSpacing;
                if (ls && ls !== 'normal') {
                    ctx.letterSpacing = `${parseFloat(ls) * dpr}px`;
                }
            }

            ctx.fillText('LIGHT', this.textCanvas.width / 2, this.textCanvas.height / 2);

            if (this.gl && this.texture) {
                const gl = this.gl;
                gl.bindTexture(gl.TEXTURE_2D, this.texture);
                gl.pixelStorei(gl.UNPACK_FLIP_Y_WEBGL, true);
                gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, gl.RGBA, gl.UNSIGNED_BYTE, this.textCanvas);
            }
        }

        startLoop() {
            if (!this.gl || this.animationFrame) return;

            const tick = () => {
                if (this.destroyed) return;
                this.updateMesh();
                this.render();
                this.animationFrame = window.requestAnimationFrame(tick);
            };

            this.animationFrame = window.requestAnimationFrame(tick);
        }

        stopLoop() {
            if (this.animationFrame) {
                window.cancelAnimationFrame(this.animationFrame);
                this.animationFrame = null;
            }
        }

        updateMesh() {
            const reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            if (reducedMotion) {
                return;
            }

            const force = FORCE;

            for (let row = 0; row < GRID_H; row += 1) {
                for (let col = 0; col < GRID_W; col += 1) {
                    const index = row * GRID_W + col;
                    const posIndex = index * 2;
                    const baseX = this.positions[posIndex];
                    const baseY = this.positions[posIndex + 1];
                    const dx = this.pointer.x - (baseX + this.displacements[posIndex]);
                    const dy = this.pointer.y - (baseY + this.displacements[posIndex + 1]);
                    const distance = Math.sqrt(dx * dx + dy * dy);
                    const proximity = Math.max(0, 1 / (1 + distance / 0.12) - 0.1);

                    if (this.pointer.inside) {
                        this.velocityX[index] += this.pointer.velocityX * force * proximity;
                        this.velocityY[index] += this.pointer.velocityY * force * proximity;
                    }

                    // Spring return & damping
                    this.velocityX[index] -= this.displacements[posIndex] * SPRING_K;
                    this.velocityY[index] -= this.displacements[posIndex + 1] * SPRING_K;
                    this.velocityX[index] *= DAMPING;
                    this.velocityY[index] *= DAMPING;

                    // Position integration
                    this.displacements[posIndex] += this.velocityX[index] * DT;
                    this.displacements[posIndex + 1] += this.velocityY[index] * DT;
                    this.displacements[posIndex] = clamp(this.displacements[posIndex], -1, 1);
                    this.displacements[posIndex + 1] = clamp(this.displacements[posIndex + 1], -1, 1);
                }
            }

            // Decay cursor velocity each frame so standing still allows spring restoration
            this.pointer.velocityX *= 0.55;
            this.pointer.velocityY *= 0.55;
            if (Math.abs(this.pointer.velocityX) < 0.0001) this.pointer.velocityX = 0;
            if (Math.abs(this.pointer.velocityY) < 0.0001) this.pointer.velocityY = 0;

            if (this.gl && this.displacementBuffer) {
                this.gl.bindBuffer(this.gl.ARRAY_BUFFER, this.displacementBuffer);
                this.gl.bufferSubData(this.gl.ARRAY_BUFFER, 0, this.displacements);
            }
        }

        render() {
            const gl = this.gl;
            if (!gl || !this.program) return;

            gl.viewport(0, 0, this.canvas.width, this.canvas.height);
            gl.clearColor(0, 0, 0, 0);
            gl.clear(gl.COLOR_BUFFER_BIT);
            gl.useProgram(this.program);

            gl.activeTexture(gl.TEXTURE0);
            gl.bindTexture(gl.TEXTURE_2D, this.texture);
            gl.uniform1i(this.uniforms.texture, 0);

            gl.uniform3fv(this.uniforms.colorA, this.colorA);
            gl.uniform3fv(this.uniforms.colorB, this.colorB);
            gl.uniform1f(this.uniforms.chroma, CHROMA);

            gl.bindBuffer(gl.ELEMENT_ARRAY_BUFFER, this.indexBuffer);
            gl.drawElements(gl.TRIANGLES, this.indices.length, gl.UNSIGNED_SHORT, 0);
        }

        resize() {
            if (!this.element || !this.canvas) return;

            const rect = this.canvas.getBoundingClientRect();
            if (rect.width <= 0 || rect.height <= 0) return;

            const dpr = Math.min(window.devicePixelRatio || 1, 2);
            const w = Math.round(rect.width * dpr);
            const h = Math.round(rect.height * dpr);

            if (this.canvas.width !== w || this.canvas.height !== h) {
                this.canvas.width = w;
                this.canvas.height = h;
            }

            if (this.gl) {
                this.gl.viewport(0, 0, this.canvas.width, this.canvas.height);
                this.createTextTexture();
                this.render();
            }
        }

        setupResizeObserver() {
            if (!('ResizeObserver' in window) || this.resizeObserver) return;
            this.resizeObserver = new ResizeObserver(() => {
                this.resize();
            });
            this.resizeObserver.observe(this.element);
            this.resizeObserver.observe(this.canvas);
        }

        cleanupWebGL() {
            if (!this.gl) return;
            const gl = this.gl;
            if (this.program) { gl.deleteProgram(this.program); this.program = null; }
            if (this.texture) { gl.deleteTexture(this.texture); this.texture = null; }
            if (this.positionBuffer) { gl.deleteBuffer(this.positionBuffer); this.positionBuffer = null; }
            if (this.uvBuffer) { gl.deleteBuffer(this.uvBuffer); this.uvBuffer = null; }
            if (this.displacementBuffer) { gl.deleteBuffer(this.displacementBuffer); this.displacementBuffer = null; }
            if (this.indexBuffer) { gl.deleteBuffer(this.indexBuffer); this.indexBuffer = null; }
            this.gl = null;
        }

        destroy() {
            this.destroyed = true;
            this.stopLoop();
            if (this.resizeObserver) {
                this.resizeObserver.disconnect();
                this.resizeObserver = null;
            }
            const targets = [this.canvas, this.element];
            targets.forEach((target) => {
                if (target) {
                    target.removeEventListener('pointerenter', this.onPointerEnter);
                    target.removeEventListener('pointermove', this.onPointerMove);
                    target.removeEventListener('pointerleave', this.onPointerLeave);
                    target.removeEventListener('pointercancel', this.onPointerLeave);
                }
            });
            this.cleanupWebGL();
            this.element.classList.remove('is-active');
        }
    }

    function initializeFooterMeshText() {
        const meshElement = document.getElementById('footer-light-mesh');
        if (!meshElement || meshElement.__footerMeshText) return;
        meshElement.__footerMeshText = new FooterMeshText(meshElement);
    }

    window.FooterMeshText = FooterMeshText;
    window.initializeFooterMeshText = initializeFooterMeshText;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeFooterMeshText, { once: true });
    } else {
        initializeFooterMeshText();
    }
})();
