<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#05060b">
    <title>kuaikuaiyun</title>
    <style>
        :root { color-scheme: dark; --ink: #f4f2ed; --muted: #9ca1ac; --line: rgba(255,255,255,.17); --violet: #bdb7ff; }
        * { box-sizing: border-box; }
        html, body { width: 100%; min-width: 320px; height: 100%; margin: 0; overflow: hidden; }
        body { background: #05060b; color: var(--ink); font-family: Inter, "PingFang SC", "Microsoft YaHei", system-ui, sans-serif; -webkit-font-smoothing: antialiased; }
        .welcome { position: relative; display: grid; grid-template-rows: auto 1fr auto; width: 100%; height: 100vh; height: 100dvh; overflow: hidden; isolation: isolate; }
        #cosmos { position: absolute; z-index: 0; inset: 0; width: 100%; height: 100%; pointer-events: none; }
        .veil { position: absolute; z-index: 1; inset: 0; background: rgba(3,4,9,.08); pointer-events: none; }
        .shell { width: min(calc(100% - 80px), 1520px); margin: 0 auto; }
        .topbar { position: relative; z-index: 2; display: flex; align-items: center; justify-content: space-between; min-height: 86px; border-bottom: 1px solid var(--line); }
        .brand { display: inline-flex; align-items: center; gap: 12px; }
        .mark { display: grid; place-items: center; width: 29px; height: 29px; border: 1px solid rgba(255,255,255,.8); font: italic 20px/1 Georgia, serif; }
        .brand-name { font-size: 12px; font-weight: 650; letter-spacing: .21em; }
        .status, .eyebrow, .footer-meta, .footer-note { color: var(--muted); font-size: 10px; font-weight: 600; letter-spacing: .2em; line-height: 1.5; text-transform: uppercase; }
        .status { display: flex; align-items: center; gap: 13px; }
        .status::before { width: 5px; height: 5px; border-radius: 50%; background: var(--violet); content: ""; }
        .hero { position: relative; z-index: 2; min-height: 0; pointer-events: none; }
        .footer { position: relative; z-index: 2; display: grid; grid-template-columns: 1fr 1fr; align-items: end; min-height: 80px; padding-bottom: 22px; border-top: 1px solid var(--line); }
        .footer-meta { justify-self: start; }
        .footer-note { justify-self: end; text-align: right; text-transform: none; }
        @media (max-width: 680px) { .shell { width: calc(100% - 40px); } .topbar { min-height: 70px; } .status { font-size: 9px; letter-spacing: .13em; } .footer { grid-template-columns: 1fr 1fr; min-height: 68px; padding-bottom: 16px; } .footer-meta, .footer-note { font-size: 8px; letter-spacing: .12em; } }
    </style>
</head>
<body>
    <main class="welcome" aria-label="快云 ERP 欢迎页">
        <canvas id="cosmos" aria-hidden="true"></canvas>
        <div class="veil" aria-hidden="true"></div>

        <header class="shell topbar">
            <div class="brand" aria-label="快云 ERP">
                <span class="brand-name">KUAIKUAIYUN</span>
            </div>
            <span class="status">系统在线</span>
        </header>

        <section class="hero" aria-hidden="true"></section>

        <footer class="shell footer">
            <span class="footer-meta">KUAIKUAIYUN</span>
            <span class="footer-note">yk Make · CHINA</span>
        </footer>
    </main>

    <script>
        (() => {
            const canvas = document.getElementById('cosmos');
            const ctx = canvas.getContext('2d');
            const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
            const random = (min, max) => Math.random() * (max - min) + min;
            let width, height, scale, streaks, animation, previousTime = 0;

            function resetStreak(streak, distant = false) {
                streak.x = random(-1.35, 1.35);
                streak.y = random(-.98, .98);
                streak.z = distant ? random(.72, 1.1) : random(.08, 1.1);
                streak.speed = random(.22, .7);
                streak.length = random(.028, .13);
                streak.alpha = random(.22, .88);
                streak.blue = Math.random() > .18;
            }

            function project(streak, depth, centerX, centerY, focal) {
                return {
                    x: centerX + (streak.x / depth) * focal,
                    y: centerY + (streak.y / depth) * focal,
                };
            }

            function drawJumpCore(x, y, time) {
                const pulse = .78 + Math.sin(time * .003) * .12;
                const radius = Math.min(width, height) * .065 * pulse;
                const halo = ctx.createRadialGradient(x, y, 0, x, y, radius * 4.5);
                halo.addColorStop(0, 'rgba(238, 247, 255, .78)');
                halo.addColorStop(.08, 'rgba(180, 210, 255, .55)');
                halo.addColorStop(.28, 'rgba(71, 126, 222, .17)');
                halo.addColorStop(1, 'rgba(5, 6, 11, 0)');
                ctx.fillStyle = halo;
                ctx.fillRect(x - radius * 4.5, y - radius * 4.5, radius * 9, radius * 9);
                ctx.beginPath();
                ctx.fillStyle = 'rgba(243, 249, 255, .82)';
                ctx.arc(x, y, Math.max(1.5, radius * .13), 0, Math.PI * 2);
                ctx.fill();
            }

            function reset() {
                cancelAnimationFrame(animation);
                width = innerWidth;
                height = innerHeight;
                scale = Math.min(devicePixelRatio || 1, 1.75);
                canvas.width = Math.round(width * scale);
                canvas.height = Math.round(height * scale);
                ctx.setTransform(scale, 0, 0, scale, 0, 0);
                const minimumStreaks = width < 700 ? 420 : 620;
                const count = Math.min(1800, Math.max(minimumStreaks, Math.round(width * height / 1350)));
                streaks = Array.from({ length: count }, () => {
                    const streak = {};
                    resetStreak(streak);
                    return streak;
                });
                previousTime = 0;
                draw(0);
            }

            function draw(time) {
                const delta = Math.min(42, time - previousTime || 16);
                previousTime = time;
                const centerX = width * .5;
                const centerY = height * .5;
                const focal = Math.max(width, height) * .48;

                ctx.fillStyle = '#05060b';
                ctx.fillRect(0, 0, width, height);
                const fog = ctx.createRadialGradient(centerX, centerY, 0, centerX, centerY, Math.max(width, height) * .8);
                fog.addColorStop(0, 'rgba(20, 41, 82, .31)');
                fog.addColorStop(.3, 'rgba(6, 14, 31, .16)');
                fog.addColorStop(1, 'rgba(1, 2, 6, .75)');
                ctx.fillStyle = fog;
                ctx.fillRect(0, 0, width, height);

                streaks.forEach(streak => {
                    streak.z -= streak.speed * delta * .00125;
                    if (streak.z < .024) resetStreak(streak, true);
                    const tailDepth = Math.min(1.18, streak.z + streak.length);
                    const from = project(streak, tailDepth, centerX, centerY, focal);
                    const to = project(streak, streak.z, centerX, centerY, focal);
                    if ((to.x < -180 || to.x > width + 180 || to.y < -180 || to.y > height + 180) && streak.z < .2) {
                        resetStreak(streak, true);
                        return;
                    }
                    const velocity = 1 - Math.min(1, streak.z);
                    const alpha = streak.alpha * (.2 + velocity * .8);
                    ctx.beginPath();
                    ctx.moveTo(from.x, from.y);
                    ctx.lineTo(to.x, to.y);
                    ctx.strokeStyle = streak.blue
                        ? `rgba(119, 176, 255, ${alpha})`
                        : `rgba(235, 243, 255, ${alpha})`;
                    ctx.lineWidth = Math.max(.45, velocity * 1.55);
                    ctx.stroke();
                });

                drawJumpCore(centerX, centerY, time);
                if (!reduced.matches) animation = requestAnimationFrame(draw);
            }

            addEventListener('resize', reset, { passive: true });
            reduced.addEventListener?.('change', reset);
            reset();
        })();
    </script>
</body>
</html>
