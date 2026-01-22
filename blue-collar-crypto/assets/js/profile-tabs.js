/**
 * Profile Tabs Enhancer
 *
 * Lightweight JS to handle profile sub-tabs
 * without breaking default navigation.
 */

document.addEventListener("DOMContentLoaded", function () {

    const tabLinks = document.querySelectorAll("[data-bcc-tab]");

    if (!tabLinks.length) {
        return;
    }

    tabLinks.forEach(link => {

        link.addEventListener("click", function (e) {

            const targetId = link.dataset.bccTab;

            if (!targetId) {
                return;
            }

            const targetPanel = document.getElementById(targetId);

            if (!targetPanel) {
                return;
            }

            // Allow normal navigation if modifier keys are used
            if (e.metaKey || e.ctrlKey || e.shiftKey) {
                return;
            }

            e.preventDefault();

            // Deactivate all tabs
            tabLinks.forEach(l => l.classList.remove("is-active"));

            // Hide all panels
            document
                .querySelectorAll("[data-bcc-tab-panel]")
                .forEach(panel => panel.classList.remove("is-active"));

            // Activate current tab + panel
            link.classList.add("is-active");
            targetPanel.classList.add("is-active");

            // Update URL hash (deep linking)
            history.replaceState(null, "", `#${targetId}`);
        });

    });

    /**
     * Activate tab from URL hash on load
     */
    const hash = window.location.hash.replace("#", "");

    if (hash) {
        const initialTab = document.querySelector(`[data-bcc-tab="${hash}"]`);
        const initialPanel = document.getElementById(hash);

        if (initialTab && initialPanel) {
            initialTab.classList.add("is-active");
            initialPanel.classList.add("is-active");
        }
    }
});
