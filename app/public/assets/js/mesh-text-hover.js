/**
 * mesh-text-hover.js — High-Performance WebGL2 + HTML5 Canvas Mesh Text Hover Component
 * 
 * Animation Stack & Techniques:
 * - 60FPS requestAnimationFrame physics loop for continuous mathematical rendering
 * - WebGL 3D GPU-accelerated rendering pipeline with 96x40 deformation grid & chromatic aberration
 * - HTML5 Canvas 2D simulation fallback if WebGL2 is unavailable
 * - Real-time Pointer & Cursor Tracking with radial bounding-box mathematics
 * - Continuous active hover deformation, velocity pull, mount harmonic wave, and mobile touch splash
 */
(function (global) {
  'use strict';

  // Simulation Constants
  const GRID_W = 96;
  const GRID_H = 40;
  const SPRING_K = 0.08;
  const DAMPING = 0.88;
  const DT = 0.12;

  // WebGL Shaders
  const VERT_SRC = `#version 300 es
in vec2 aPos;
in vec2 aUv;
in vec2 aDisp;
out vec2 vUv;
out float vMag;

void main() {
    gl_Position = vec4(aPos + aDisp, 0.0, 1.0);
    vUv = aUv;
    vMag = length(aDisp);
}`;

  const FRAG_SRC = `#version 300 es
precision highp float;
in vec2 vUv;
in float vMag;
out vec4 outColor;

uniform sampler2D uTex;
uniform float uChroma;
uniform vec3 uColorA;
uniform vec3 uColorB;

void main() {
    vec4 base = texture(uTex, vUv);
    if (uChroma > 0.0 && vMag > 0.0004) {
        float o = uChroma * 0.016 * clamp(vMag * 14.0, 0.0, 1.0);
        float aOff = texture(uTex, vUv + vec2(o, 0.0)).a;
        float bOff = texture(uTex, vUv - vec2(o, 0.0)).a;
        vec3 col = base.rgb * base.a;
        col += uColorA * max(0.0, aOff - base.a * 0.8);
        col += uColorB * max(0.0, bOff - base.a * 0.8);
        float aMax = max(base.a, max(aOff, bOff));
        outColor = vec4(col, aMax);
    } else {
        outColor = vec4(base.rgb * base.a, base.a);
    }
}`;

  function compileShader(gl, type, src) {
    const sh = gl.createShader(type);
    gl.shaderSource(sh, src);
    gl.compileShader(sh);
    if (!gl.getShaderParameter(sh, gl.COMPILE_STATUS)) {
      console.warn('MeshText Shader compile error:', gl.getShaderInfoLog(sh));
      gl.deleteShader(sh);
      return null;
    }
    return sh;
  }

  function linkProgram(gl, vs, fs) {
    const p = gl.createProgram();
    gl.attachShader(p, vs);
    gl.attachShader(p, fs);
    gl.linkProgram(p);
    if (!gl.getProgramParameter(p, gl.LINK_STATUS)) {
      console.warn('MeshText Program link error:', gl.getProgramInfoLog(p));
      gl.deleteProgram(p);
      return null;
    }
    return p;
  }

  function renderTextToCanvas(text, color, fontFamily, fontWeight, fontSize, letterSpacing, width, height) {
    const c = document.createElement('canvas');
    c.width = width;
    c.height = height;
    const ctx = c.getContext('2d');
    if (!ctx) return c;

    ctx.clearRect(0, 0, width, height);
    ctx.fillStyle = color;
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';

    const fontDeclaration = `${fontWeight} ${fontSize}px ${fontFamily}`;
    ctx.font = fontDeclaration;

    if ('letterSpacing' in ctx && letterSpacing && letterSpacing !== 'normal') {
      try {
        ctx.letterSpacing = letterSpacing;
      } catch (_) {}
    }

    ctx.fillText(text, width / 2, height / 2);
    return c;
  }

  class MeshTextHover {
    constructor(wrapperEl, options = {}) {
      if (!wrapperEl) return;
      this.wrapper = wrapperEl;
      this.text = options.text || wrapperEl.getAttribute('data-text') || 'LIGHT';
      this.force = 4.2; // Generous responsive force for visible deformation
      this.colorSplit = options.colorSplit !== false;
      this.customColors = [
        [1.0, 0.0, 0.5],   // neon magenta (#ff007f)
        [0.0, 0.95, 1.0],  // electric cyan (#00f0ff)
        [0.55, 0.0, 1.0]   // vivid violet (#8c00ff)
      ];

      this.canvas = wrapperEl.querySelector('canvas.nex-mesh-text__canvas');
      this.measureEl = wrapperEl.querySelector('.nex-mesh-text__measure');

      if (!this.canvas) {
        this.canvas = document.createElement('canvas');
        this.canvas.className = 'nex-mesh-text__canvas';
        this.canvas.setAttribute('aria-hidden', 'true');
        this.wrapper.appendChild(this.canvas);
      }

      if (!this.measureEl) {
        this.measureEl = document.createElement('span');
        this.measureEl.className = 'nex-mesh-text__measure';
        this.measureEl.setAttribute('aria-hidden', 'true');
        this.measureEl.textContent = this.text;
        this.wrapper.insertBefore(this.measureEl, this.canvas);
      } else {
        this.measureEl.textContent = this.text;
      }

      // Try WebGL2 first
      this.gl = this.canvas.getContext('webgl2', {
        alpha: true,
        premultipliedAlpha: true,
        antialias: true,
        depth: false,
        stencil: false,
        preserveDrawingBuffer: false,
      });

      if (this.gl) {
        this.mode = 'webgl2';
        this.initWebGL();
      } else {
        // Fallback to 2D Canvas particle/mesh engine (never fails)
        this.ctx2d = this.canvas.getContext('2d');
        if (this.ctx2d) {
          this.mode = 'canvas2d';
          this.init2D();
        } else {
          this.wrapper.classList.add('is-hard-error');
        }
      }
    }

    initWebGL() {
      const gl = this.gl;

      this.vs = compileShader(gl, gl.VERTEX_SHADER, VERT_SRC);
      this.fs = compileShader(gl, gl.FRAGMENT_SHADER, FRAG_SRC);
      if (!this.vs || !this.fs) {
        this.fallbackTo2D();
        return;
      }
      this.program = linkProgram(gl, this.vs, this.fs);
      if (!this.program) {
        this.fallbackTo2D();
        return;
      }

      // Grid geometry
      this.vertCount = (GRID_W + 1) * (GRID_H + 1);
      this.positions = new Float32Array(this.vertCount * 2);
      this.uvs = new Float32Array(this.vertCount * 2);

      for (let y = 0; y <= GRID_H; y++) {
        for (let x = 0; x <= GRID_W; x++) {
          const i = y * (GRID_W + 1) + x;
          const u = x / GRID_W;
          const v = y / GRID_H;
          this.positions[i * 2] = u * 2 - 1;
          this.positions[i * 2 + 1] = 1 - v * 2;
          this.uvs[i * 2] = u;
          this.uvs[i * 2 + 1] = v;
        }
      }

      const indexCount = GRID_W * GRID_H * 6;
      const indices = new Uint32Array(indexCount);
      let idx = 0;
      for (let y = 0; y < GRID_H; y++) {
        for (let x = 0; x < GRID_W; x++) {
          const a = y * (GRID_W + 1) + x;
          const b = a + 1;
          const c = a + (GRID_W + 1);
          const d = c + 1;
          indices[idx++] = a; indices[idx++] = c; indices[idx++] = b;
          indices[idx++] = b; indices[idx++] = c; indices[idx++] = d;
        }
      }
      this.indexCount = indexCount;

      this.disp = new Float32Array(this.vertCount * 2);
      this.vel = new Float32Array(this.vertCount * 2);

      this.aPos = gl.getAttribLocation(this.program, 'aPos');
      this.aUv = gl.getAttribLocation(this.program, 'aUv');
      this.aDisp = gl.getAttribLocation(this.program, 'aDisp');
      this.uTex = gl.getUniformLocation(this.program, 'uTex');
      this.uChroma = gl.getUniformLocation(this.program, 'uChroma');
      this.uColorA = gl.getUniformLocation(this.program, 'uColorA');
      this.uColorB = gl.getUniformLocation(this.program, 'uColorB');

      this.vao = gl.createVertexArray();
      gl.bindVertexArray(this.vao);

      this.posBuf = gl.createBuffer();
      gl.bindBuffer(gl.ARRAY_BUFFER, this.posBuf);
      gl.bufferData(gl.ARRAY_BUFFER, this.positions, gl.STATIC_DRAW);
      gl.enableVertexAttribArray(this.aPos);
      gl.vertexAttribPointer(this.aPos, 2, gl.FLOAT, false, 0, 0);

      this.uvBuf = gl.createBuffer();
      gl.bindBuffer(gl.ARRAY_BUFFER, this.uvBuf);
      gl.bufferData(gl.ARRAY_BUFFER, this.uvs, gl.STATIC_DRAW);
      gl.enableVertexAttribArray(this.aUv);
      gl.vertexAttribPointer(this.aUv, 2, gl.FLOAT, false, 0, 0);

      this.dispBuf = gl.createBuffer();
      gl.bindBuffer(gl.ARRAY_BUFFER, this.dispBuf);
      gl.bufferData(gl.ARRAY_BUFFER, this.disp, gl.DYNAMIC_DRAW);
      gl.enableVertexAttribArray(this.aDisp);
      gl.vertexAttribPointer(this.aDisp, 2, gl.FLOAT, false, 0, 0);

      this.idxBuf = gl.createBuffer();
      gl.bindBuffer(gl.ELEMENT_ARRAY_BUFFER, this.idxBuf);
      gl.bufferData(gl.ELEMENT_ARRAY_BUFFER, indices, gl.STATIC_DRAW);

      this.tex = gl.createTexture();
      gl.bindTexture(gl.TEXTURE_2D, this.tex);
      gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE);
      gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);
      gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR);
      gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR);

      this.cursor = { x: 99, y: 99, px: 99, py: 99, vx: 0, vy: 0, inside: false, isDown: false };

      this.bindEvents();
      this.resize();
      this.rebuildTexSync();

      if (typeof document !== 'undefined' && document.fonts?.ready) {
        document.fonts.ready.then(() => {
          this.rebuildTexSync();
        }).catch(() => {});
      }

      this.setupObservers();
      this.triggerMountWave();

      this.running = true;
      this.rafId = requestAnimationFrame(this.tickWebGL.bind(this));
    }

    fallbackTo2D() {
      this.mode = 'canvas2d';
      this.ctx2d = this.canvas.getContext('2d');
      if (this.ctx2d) {
        this.init2D();
      } else {
        this.wrapper.classList.add('is-hard-error');
      }
    }

    init2D() {
      this.cursor = { x: 99, y: 99, px: 99, py: 99, vx: 0, vy: 0, inside: false, isDown: false };
      this.bindEvents();
      this.resize();
      this.disp2DX = 0;
      this.disp2DY = 0;
      this.vel2DX = 0;
      this.vel2DY = 0;
      this.setupObservers();
      this.running = true;
      this.rafId = requestAnimationFrame(this.tick2D.bind(this));
    }

    getTypography() {
      // Read style from parent element (h2) so we get the real text color and font
      const parent = this.wrapper.parentElement || this.wrapper;
      const comp = window.getComputedStyle(parent);
      let color = comp.color;
      if (!color || color === 'transparent' || color === 'rgba(0, 0, 0, 0)') {
        color = '#080808';
      }
      return {
        fontFamily: comp.fontFamily || "'Satoshi', 'Outfit', 'Inter', sans-serif",
        fontWeight: comp.fontWeight || '900',
        fontSize: parseFloat(comp.fontSize) || 96,
        letterSpacing: comp.letterSpacing !== 'normal' ? comp.letterSpacing : '-0.05em',
        color: color,
      };
    }

    rebuildTexSync() {
      if (this.destroyed || this.mode !== 'webgl2') return;
      const canvas = this.canvas;
      const gl = this.gl;
      if (!canvas || !gl) return;

      const w = Math.max(2, canvas.width);
      const h = Math.max(2, canvas.height);
      const dpr = Math.min(window.devicePixelRatio || 1, 2);
      const typo = this.getTypography();
      const realSize = typo.fontSize * dpr;

      const c2 = renderTextToCanvas(
        String(this.text || 'LIGHT'),
        typo.color,
        typo.fontFamily,
        typo.fontWeight,
        realSize,
        typo.letterSpacing,
        w,
        h
      );

      this.cachedTextCanvas = c2;
      gl.bindTexture(gl.TEXTURE_2D, this.tex);
      gl.pixelStorei(gl.UNPACK_PREMULTIPLY_ALPHA_WEBGL, true);
      gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, gl.RGBA, gl.UNSIGNED_BYTE, c2);
    }

    resize() {
      if (this.destroyed) return;
      const dpr = Math.min(window.devicePixelRatio || 1, 2);
      const rect = this.wrapper.getBoundingClientRect();
      const w = Math.max(2, Math.round(rect.width * 1.4 * dpr));
      const h = Math.max(2, Math.round(rect.height * 1.6 * dpr));

      if (this.canvas.width !== w || this.canvas.height !== h) {
        this.canvas.width = w;
        this.canvas.height = h;
        if (this.mode === 'webgl2' && this.gl) {
          this.gl.viewport(0, 0, w, h);
          this.rebuildTexSync();
        }
      }
    }

    triggerMountWave() {
      if (this.mode === 'webgl2') {
        const vertCount = this.vertCount;
        const pos = this.positions;
        const vel = this.vel;
        const strength = 0.12;

        for (let i = 0; i < vertCount; i++) {
          const i2 = i * 2;
          const px = pos[i2];
          const py = pos[i2 + 1];
          const dist = Math.hypot(px, py);
          const wave = Math.sin(dist * Math.PI * 3.0) * Math.exp(-dist * 1.2);
          vel[i2] += px * wave * strength;
          vel[i2 + 1] += (py + 0.3) * wave * strength;
        }
      }
    }

    triggerSplash(normalizedX, normalizedY, strength = 0.22) {
      if (this.mode === 'webgl2') {
        const vertCount = this.vertCount;
        const pos = this.positions;
        const vel = this.vel;

        for (let i = 0; i < vertCount; i++) {
          const i2 = i * 2;
          const px = pos[i2];
          const py = pos[i2 + 1];
          const dx = px - normalizedX;
          const dy = py - normalizedY;
          const dist = Math.hypot(dx, dy);
          const prox = Math.max(0, 1 / (1 + dist / 0.18) - 0.15);
          vel[i2] += dx * prox * strength;
          vel[i2 + 1] += dy * prox * strength;
        }
      }
    }

    bindEvents() {
      this.onMove = (e) => {
        const rect = this.canvas.getBoundingClientRect();
        if (rect.width <= 0 || rect.height <= 0) return;
        const nx = (e.clientX - rect.left) / rect.width;
        const ny = (e.clientY - rect.top) / rect.height;
        const x = nx * 2 - 1;
        const y = 1 - ny * 2;

        if (!this.cursor.inside) {
          this.cursor.px = x;
          this.cursor.py = y;
          this.cursor.inside = true;
          this.triggerSplash(x, y, 0.12);
        }
        this.cursor.x = x;
        this.cursor.y = y;
      };

      this.onEnter = (e) => {
        this.onMove(e);
      };

      this.onDown = (e) => {
        this.cursor.isDown = true;
        this.onMove(e);
        if (e.pointerId !== undefined && typeof this.wrapper.setPointerCapture === 'function') {
          try {
            this.wrapper.setPointerCapture(e.pointerId);
          } catch (_) {}
        }
        this.triggerSplash(this.cursor.x, this.cursor.y, 0.28);
      };

      this.onUp = (e) => {
        this.cursor.isDown = false;
        if (e.pointerId !== undefined && typeof this.wrapper.releasePointerCapture === 'function') {
          try {
            if (this.wrapper.hasPointerCapture(e.pointerId)) {
              this.wrapper.releasePointerCapture(e.pointerId);
            }
          } catch (_) {}
        }
        this.onMove(e);
      };

      this.onLeave = () => {
        if (!this.cursor.isDown) {
          this.cursor.inside = false;
          this.cursor.x = 99;
          this.cursor.y = 99;
          this.cursor.vx = 0;
          this.cursor.vy = 0;
        }
      };

      this.onCancel = (e) => {
        this.cursor.isDown = false;
        this.onLeave();
      };

      const opts = { passive: true };
      this.wrapper.addEventListener('pointerenter', this.onEnter, opts);
      this.wrapper.addEventListener('pointermove', this.onMove, opts);
      this.wrapper.addEventListener('pointerdown', this.onDown, opts);
      this.wrapper.addEventListener('pointerup', this.onUp, opts);
      this.wrapper.addEventListener('pointerleave', this.onLeave, opts);
      this.wrapper.addEventListener('pointercancel', this.onCancel, opts);

      this.canvas.addEventListener('pointerenter', this.onEnter, opts);
      this.canvas.addEventListener('pointermove', this.onMove, opts);
      this.canvas.addEventListener('pointerdown', this.onDown, opts);
    }

    setupObservers() {
      if (typeof ResizeObserver !== 'undefined') {
        this.ro = new ResizeObserver(() => {
          this.resize();
        });
        this.ro.observe(this.wrapper);
      }

      this.onVisibilityChange = () => {
        if (document.hidden) {
          this.running = false;
          if (this.rafId) {
            cancelAnimationFrame(this.rafId);
            this.rafId = 0;
          }
        } else {
          if (!this.running) {
            this.running = true;
            this.rafId = requestAnimationFrame(
              this.mode === 'webgl2' ? this.tickWebGL.bind(this) : this.tick2D.bind(this)
            );
          }
        }
      };
      document.addEventListener('visibilitychange', this.onVisibilityChange);
    }

    tickWebGL() {
      if (!this.running || this.destroyed) return;

      const cursor = this.cursor;
      cursor.vx = cursor.x - cursor.px;
      cursor.vy = cursor.y - cursor.py;
      const vmag = Math.hypot(cursor.vx, cursor.vy);
      if (vmag > 0.45) {
        cursor.vx = 0;
        cursor.vy = 0;
      }
      cursor.px = cursor.x;
      cursor.py = cursor.y;

      const vertCount = this.vertCount;
      const positions = this.positions;
      const disp = this.disp;
      const vel = this.vel;
      const fpull = this.force;
      const isInside = cursor.inside;

      for (let i = 0; i < vertCount; i++) {
        const i2 = i * 2;
        const px = positions[i2];
        const py = positions[i2 + 1];
        const dx = disp[i2];
        const dy = disp[i2 + 1];

        const cx = cursor.x - (px + dx);
        const cy = cursor.y - (py + dy);
        const cd = Math.hypot(cx, cy);

        // Generous, organic influence radius
        const proximity = Math.max(0, 1 / (1 + cd / 0.26) - 0.12);

        let vx = vel[i2];
        let vy = vel[i2 + 1];

        // Velocity drag from cursor movement
        vx += cursor.vx * fpull * proximity;
        vy += cursor.vy * fpull * proximity;

        // Continuous active hover bulge: the letters part and ripple organically under the mouse
        if (isInside && proximity > 0) {
          const repel = proximity * (cursor.isDown ? 0.055 : 0.032);
          const angle = Math.atan2(cy, cx);
          vx -= Math.cos(angle) * repel;
          vy -= Math.sin(angle) * repel;
        }

        vx -= dx * SPRING_K;
        vy -= dy * SPRING_K;

        vx *= DAMPING;
        vy *= DAMPING;

        vel[i2] = vx;
        vel[i2 + 1] = vy;

        let ndx = dx + vx * DT;
        let ndy = dy + vy * DT;

        if (ndx > 1.3) ndx = 1.3; else if (ndx < -1.3) ndx = -1.3;
        if (ndy > 1.3) ndy = 1.3; else if (ndy < -1.3) ndy = -1.3;

        disp[i2] = ndx;
        disp[i2 + 1] = ndy;
      }

      const gl = this.gl;
      gl.bindBuffer(gl.ARRAY_BUFFER, this.dispBuf);
      gl.bufferSubData(gl.ARRAY_BUFFER, 0, disp);

      gl.clearColor(0, 0, 0, 0);
      gl.clear(gl.COLOR_BUFFER_BIT);

      gl.useProgram(this.program);
      gl.activeTexture(gl.TEXTURE0);
      gl.bindTexture(gl.TEXTURE_2D, this.tex);
      gl.uniform1i(this.uTex, 0);
      gl.uniform1f(this.uChroma, this.colorSplit ? 1.0 : 0.0);

      const cycleMs = 360;
      const idx = Math.floor(performance.now() / cycleMs) % this.customColors.length;
      const cA = this.customColors[idx];
      const cB = this.customColors[(idx + 1) % this.customColors.length];

      gl.uniform3f(this.uColorA, cA[0], cA[1], cA[2]);
      gl.uniform3f(this.uColorB, cB[0], cB[1], cB[2]);

      gl.enable(gl.BLEND);
      gl.blendFunc(gl.ONE, gl.ONE_MINUS_SRC_ALPHA);
      gl.bindVertexArray(this.vao);
      gl.drawElements(gl.TRIANGLES, this.indexCount, gl.UNSIGNED_INT, 0);

      this.rafId = requestAnimationFrame(this.tickWebGL.bind(this));
    }

    tick2D() {
      if (!this.running || this.destroyed || !this.ctx2d) return;

      const cursor = this.cursor;
      cursor.vx = cursor.x - cursor.px;
      cursor.vy = cursor.y - cursor.py;
      cursor.px = cursor.x;
      cursor.py = cursor.y;

      const targetX = cursor.inside ? cursor.x * 24 : 0;
      const targetY = cursor.inside ? -cursor.y * 18 : 0;

      this.vel2DX += (targetX - this.disp2DX) * 0.1;
      this.vel2DY += (targetY - this.disp2DY) * 0.1;
      this.vel2DX *= 0.82;
      this.vel2DY *= 0.82;
      this.disp2DX += this.vel2DX;
      this.disp2DY += this.vel2DY;

      const ctx = this.ctx2d;
      const w = this.canvas.width;
      const h = this.canvas.height;
      ctx.clearRect(0, 0, w, h);

      const typo = this.getTypography();
      const dpr = Math.min(window.devicePixelRatio || 1, 2);
      const realSize = typo.fontSize * dpr;

      ctx.save();
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      ctx.font = `${typo.fontWeight} ${realSize}px ${typo.fontFamily}`;
      if ('letterSpacing' in ctx && typo.letterSpacing) {
        try { ctx.letterSpacing = typo.letterSpacing; } catch (_) {}
      }

      // Chromatic separation fringe
      if (Math.hypot(this.disp2DX, this.disp2DY) > 0.5) {
        ctx.fillStyle = '#ff007f';
        ctx.fillText(this.text, w / 2 + this.disp2DX + 4, h / 2 + this.disp2DY);

        ctx.fillStyle = '#00f0ff';
        ctx.fillText(this.text, w / 2 + this.disp2DX - 4, h / 2 + this.disp2DY);
      }

      ctx.fillStyle = typo.color;
      ctx.fillText(this.text, w / 2 + this.disp2DX, h / 2 + this.disp2DY);
      ctx.restore();

      this.rafId = requestAnimationFrame(this.tick2D.bind(this));
    }

    destroy() {
      this.destroyed = true;
      this.running = false;
      if (this.rafId) cancelAnimationFrame(this.rafId);
      if (this.ro) this.ro.disconnect();
      document.removeEventListener('visibilitychange', this.onVisibilityChange);

      const gl = this.gl;
      if (gl) {
        if (this.posBuf) gl.deleteBuffer(this.posBuf);
        if (this.uvBuf) gl.deleteBuffer(this.uvBuf);
        if (this.dispBuf) gl.deleteBuffer(this.dispBuf);
        if (this.idxBuf) gl.deleteBuffer(this.idxBuf);
        if (this.tex) gl.deleteTexture(this.tex);
        if (this.vao) gl.deleteVertexArray(this.vao);
        if (this.program) gl.deleteProgram(this.program);
        if (this.vs) gl.deleteShader(this.vs);
        if (this.fs) gl.deleteShader(this.fs);
      }
    }
  }

  function autoInit() {
    // Section 01 headline brand + Giant footer brand
    const targets = document.querySelectorAll('.nex-mesh-text[data-text], #about-mesh-light, #footer-light-brand');
    targets.forEach((el) => {
      if (!el._nexMeshHover) {
        el.classList.add('nex-mesh-text');
        if (!el.getAttribute('data-text')) {
          el.setAttribute('data-text', (el.textContent || 'LIGHT').trim());
        }
        el._nexMeshHover = new MeshTextHover(el, {
          text: el.getAttribute('data-text') || 'LIGHT',
        });
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', autoInit);
  } else {
    autoInit();
  }

  global.MeshTextHover = MeshTextHover;

})(typeof window !== 'undefined' ? window : this);
