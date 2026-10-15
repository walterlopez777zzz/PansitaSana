<?php
/**
 * Carrito — marcado estático del cajón (<dialog>) y plantilla de línea.
 *
 * Un único árbol DOM sirve a escritorio (panel lateral) y móvil (hoja
 * inferior); la diferencia la resuelve /cart/cart.css por media query. La
 * lógica vive en /cart/index.js (ESM). Todos los nodos que el módulo actualiza
 * están marcados con `data-cart-slot`; los controles, con `data-cart-action`.
 *
 * La miniatura de cada línea reproduce la anatomía de una tarjeta 1x1 de la
 * tienda (`.item-tile` > `.item-image-container` > `.item-image`): así hereda
 * el degradado, la caja inferior del 85 % y el encuadre por tipo/objeto que
 * define style.css, sin duplicar reglas.
 */
?>
    <dialog id="cart-drawer" class="cart-drawer" data-state="idle"
        aria-labelledby="cart-drawer-title" aria-describedby="cart-drawer-summary">
        <form method="dialog" class="cart-drawer__panel">
            <header class="cart-drawer__header">
                <span class="cart-drawer__grabber" aria-hidden="true"></span>
                <div class="cart-drawer__heading">
                    <h2 id="cart-drawer-title" class="cart-drawer__title" tabindex="-1">Tu carrito</h2>
                    <p id="cart-drawer-summary" class="cart-drawer__summary">
                        <output class="cart-drawer__count" data-cart-slot="count" aria-live="polite">0 artículos</output>
                    </p>
                </div>
                <button type="submit" class="cart-drawer__close" data-cart-action="close" aria-label="Cerrar carrito">
                    <svg aria-hidden="true" focusable="false"><use href="#icon-close"></use></svg>
                </button>
            </header>

            <section class="cart-drawer__body" aria-label="Artículos del carrito">
                <ul class="cart-lines" data-cart-slot="lines"></ul>

                <div class="cart-empty" data-cart-slot="empty" hidden>
                    <svg class="cart-empty__icon" aria-hidden="true" focusable="false"><use href="#icon-cart"></use></svg>
                    <p class="cart-empty__title">Tu carrito está vacío</p>
                    <p class="cart-empty__text">Explora la tienda para encontrar ofertas.</p>
                    <button type="submit" class="cart-cta cart-cta--secondary" data-cart-action="close">Seguir explorando</button>
                </div>

                <div class="cart-drawer__loading" data-cart-slot="loading" hidden>
                    <span class="cart-skeleton" aria-hidden="true"></span>
                    <span class="cart-skeleton" aria-hidden="true"></span>
                    <span class="cart-drawer__loading-text">Cargando tu carrito…</span>
                </div>

                <div class="cart-drawer__error" data-cart-slot="error" hidden>
                    <p class="cart-drawer__error-text" data-cart-slot="error-text">No pudimos cargar tu carrito.</p>
                    <button type="button" class="cart-cta cart-cta--secondary" data-cart-action="retry">Reintentar</button>
                </div>
            </section>

            <p class="cart-drawer__notice" data-cart-slot="notice" role="status" aria-live="polite"></p>

            <footer class="cart-drawer__footer">
                <dl class="cart-totals">
                    <div class="cart-totals__row cart-totals__row--savings" data-cart-slot="savings-row" hidden>
                        <dt>Ahorro</dt>
                        <dd><output data-cart-slot="savings">S/ 0.00</output></dd>
                    </div>
                    <div class="cart-totals__row cart-totals__row--subtotal">
                        <dt>Subtotal</dt>
                        <dd><output data-cart-slot="subtotal" aria-live="polite">S/ 0.00</output></dd>
                    </div>
                </dl>
                <a class="cart-cta cart-cta--primary" href="/checkout/" data-cart-slot="checkout" aria-disabled="true" tabindex="-1">Finalizar compra</a>
            </footer>
        </form>
    </dialog>

    <template id="cart-line-template">
        <li class="cart-line" data-offer-id="" data-state="idle">
            <button type="button" class="cart-line__media item-tile" data-cart-slot="media" data-cart-action="view" aria-label="Ver artículo">
                <span class="cart-line__tile item-image-container" data-cart-slot="tile">
                    <img class="cart-line__image item-image" data-cart-slot="image" alt="" loading="lazy" decoding="async">
                </span>
                <svg class="cart-line__fallback" aria-hidden="true" focusable="false"><use href="#icon-cart"></use></svg>
            </button>
            <div class="cart-line__body">
                <h3 class="cart-line__name" data-cart-slot="name"></h3>
                <p class="cart-line__meta">
                    <span class="cart-pill" data-cart-slot="rarity" hidden></span>
                    <span class="cart-line__type" data-cart-slot="type" hidden></span>
                </p>
            </div>
            <p class="cart-line__price">
                <s class="cart-line__price-base" data-cart-slot="price-base" hidden></s>
                <span class="cart-line__price-final" data-cart-slot="price"></span>
            </p>
            <div class="cart-stepper" role="group" aria-label="Cantidad">
                <button type="button" class="cart-stepper__btn" data-cart-action="decrement" aria-label="Reducir cantidad">
                    <svg class="cart-stepper__icon cart-stepper__icon--minus" aria-hidden="true" focusable="false"><use href="#icon-minus"></use></svg>
                    <svg class="cart-stepper__icon cart-stepper__icon--trash" viewBox="0 0 100 100" aria-hidden="true" focusable="false"><path fill="none" stroke="currentColor" stroke-width="10" stroke-linecap="round" stroke-linejoin="round" d="M35 12H65M12 32H88M25 32L34 90H66L75 32"/></svg>
                </button>
                <output class="cart-stepper__value" data-cart-slot="quantity">1</output>
                <button type="button" class="cart-stepper__btn" data-cart-action="increment" aria-label="Aumentar cantidad">
                    <svg class="cart-stepper__icon" aria-hidden="true" focusable="false"><use href="#icon-plus"></use></svg>
                </button>
            </div>
        </li>
    </template>
