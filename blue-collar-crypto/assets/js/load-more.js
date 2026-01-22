/**
 * Load More Utility
 *
 * Generic "Load More" handler for BCC lists
 * Requires:
 * - A button with data attributes
 * - A backend AJAX handler
 */

document.addEventListener("DOMContentLoaded", function () {

    const buttons = document.querySelectorAll("[data-bcc-load-more]");

    if (!buttons.length) {
        return;
    }

    buttons.forEach(button => {

        button.addEventListener("click", function (e) {
            e.preventDefault();

            if (button.classList.contains("is-loading")) {
                return;
            }

            const containerSelector = button.dataset.container;
            const action            = button.dataset.action;
            const page              = parseInt(button.dataset.page, 10) || 1;

            if (!containerSelector || !action) {
                console.warn("BCC Load More: Missing data attributes");
                return;
            }

            const container = document.querySelector(containerSelector);

            if (!container) {
                console.warn("BCC Load More: Container not found");
                return;
            }

            button.classList.add("is-loading");
            button.disabled = true;
            button.innerText = "Loading...";

            const params = new URLSearchParams({
                action: action,
                page: page + 1,
            });

            fetch(bccLoadMore.ajaxUrl, {
                method: "POST",
                headers: {
                    "Content-Type": "application/x-www-form-urlencoded",
                },
                body: params.toString(),
            })
            .then(response => response.text())
            .then(html => {

                if (!html || html.trim() === "") {
                    button.remove();
                    return;
                }

                container.insertAdjacentHTML("beforeend", html);

                // increment page
                button.dataset.page = page + 1;

                button.classList.remove("is-loading");
                button.disabled = false;
                button.innerText = "Load More";

            })
            .catch(error => {
                console.error("BCC Load More Error:", error);
                button.classList.remove("is-loading");
                button.disabled = false;
                button.innerText = "Load More";
            });

        });

    });

});
