import { useEffect, useRef } from 'react'
import { MotionStage } from './MarketingDemos'

// Project a fixed point cloud once; motion is CSS-only, with no render loop.
const sphereDots = []
for (let row = 1; row < 24; row += 1) {
    const latitude = (row / 24) * Math.PI
    const ring = Math.sin(latitude)
    const count = Math.max(10, Math.round(72 * ring))
    for (let column = 0; column < count; column += 1) {
        const longitude = (column / count) * Math.PI * 2 + row * 0.09
        const depth = ring * Math.cos(longitude)
        if (depth < -0.2) continue
        const x = ring * Math.sin(longitude) * 298
        const y = Math.cos(latitude) * 298
        sphereDots.push({
            x: 500 + x * 0.97 - y * 0.2,
            y: 350 + y * 0.97 + x * 0.2,
            radius: 1.1 + Math.max(0, depth) * 0.85,
            opacity: 0.2 + Math.max(0, depth) * 0.5,
        })
    }
}

export default function HeroBackdrop() {
    const backdrop = useRef(null)

    useEffect(() => {
        const node = backdrop.current
        const hero = node?.closest('.m-hero')
        if (!node || !hero) return

        const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)')
        const finePointer = window.matchMedia('(pointer: fine)')
        let frame = null
        let nextPoint = null

        const draw = () => {
            frame = null
            if (!nextPoint) return
            const bounds = hero.getBoundingClientRect()
            const x = Math.min(1, Math.max(0, (nextPoint.x - bounds.left) / bounds.width))
            const y = Math.min(1, Math.max(0, (nextPoint.y - bounds.top) / bounds.height))
            node.style.setProperty('--hero-pointer-x', `${x * 100}%`)
            node.style.setProperty('--hero-pointer-y', `${y * 100}%`)
            node.style.setProperty('--hero-shift-x', `${(x - 0.5) * 54}px`)
            node.style.setProperty('--hero-shift-y', `${(y - 0.5) * 28}px`)
            node.style.setProperty('--hero-tilt-x', `${(0.5 - y) * 9}deg`)
            node.style.setProperty('--hero-tilt-y', `${(x - 0.5) * 13}deg`)
        }
        const move = (event) => {
            if (
                reducedMotion.matches ||
                !finePointer.matches ||
                node.closest('.marketing-site')?.dataset.motionPaused === 'true'
            )
                return
            nextPoint = { x: event.clientX, y: event.clientY }
            if (frame === null) frame = window.requestAnimationFrame(draw)
        }
        const reset = () => {
            nextPoint = null
            node.style.removeProperty('--hero-pointer-x')
            node.style.removeProperty('--hero-pointer-y')
            node.style.removeProperty('--hero-shift-x')
            node.style.removeProperty('--hero-shift-y')
            node.style.removeProperty('--hero-tilt-x')
            node.style.removeProperty('--hero-tilt-y')
        }

        hero.addEventListener('pointermove', move, { passive: true })
        hero.addEventListener('pointerleave', reset)
        return () => {
            hero.removeEventListener('pointermove', move)
            hero.removeEventListener('pointerleave', reset)
            if (frame !== null) window.cancelAnimationFrame(frame)
        }
    }, [])

    return (
        <div className="m-hero-backdrop" aria-hidden="true" ref={backdrop}>
            <MotionStage className="m-hero-atmosphere">
                <span className="m-hero-stars" />
                <span className="m-hero-pointer-light" />
                <span className="m-hero-glow m-hero-glow-peach" />
                <span className="m-hero-glow m-hero-glow-sage" />
                <span className="m-hero-horizon" />
                <div className="m-hero-sphere-position">
                    <svg className="m-hero-sphere" viewBox="0 0 1000 700" fill="none" focusable="false">
                        <g fill="currentColor">
                            {sphereDots.map((dot, index) => (
                                <circle key={index} cx={dot.x} cy={dot.y} r={dot.radius} opacity={dot.opacity} />
                            ))}
                        </g>
                        <ellipse
                            cx="500"
                            cy="350"
                            rx="384"
                            ry="165"
                            stroke="currentColor"
                            strokeOpacity="0.17"
                            strokeDasharray="2 11"
                            transform="rotate(-24 500 350)"
                        />
                        <ellipse
                            cx="500"
                            cy="350"
                            rx="340"
                            ry="288"
                            stroke="currentColor"
                            strokeOpacity="0.1"
                            transform="rotate(18 500 350)"
                        />
                    </svg>
                </div>
                <span className="m-hero-vignette" />
            </MotionStage>
        </div>
    )
}
