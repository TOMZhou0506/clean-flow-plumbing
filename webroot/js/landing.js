/**
 * Landing page behaviour.
 *
 * - .hero-scroll  the "See more" arrow at the bottom of the hero. It hides once
 *                 the visitor has scrolled a little and comes back at the top.
 */
document.addEventListener('DOMContentLoaded', function () {
    var arrow = document.querySelector('.hero-scroll');
    if (!arrow) {
        return;
    }

    function update() {
        arrow.classList.toggle('is-hidden', window.scrollY > 80);
    }

    window.addEventListener('scroll', update, { passive: true });
    update();
});
