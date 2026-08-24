(function () {
  "use strict";

  window.cornqCopyText = async function (text, button) {
    text = String(text || "").trim();
    if (!text) return false;

    let copied = false;

    try {
      if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(text);
        copied = true;
      }
    } catch (error) {
      copied = false;
    }

    if (!copied) {
      const textarea = document.createElement("textarea");
      textarea.value = text;
      textarea.setAttribute("readonly", "");
      textarea.style.position = "fixed";
      textarea.style.left = "-9999px";
      textarea.style.top = "0";
      document.body.appendChild(textarea);
      textarea.focus();
      textarea.select();

      try {
        copied = document.execCommand("copy");
      } catch (error) {
        copied = false;
      }

      document.body.removeChild(textarea);
    }

    if (button && button.dataset.iconButton === "true") {
      const originalLabel = button.getAttribute("aria-label") || "Copy";
      const originalTitle = button.getAttribute("title") || originalLabel;
      const feedback = copied ? "Copied" : "Copy failed";

      button.setAttribute("aria-label", feedback);
      button.setAttribute("title", feedback);
      button.classList.toggle("copy-success", copied);

      setTimeout(() => {
        button.setAttribute("aria-label", originalLabel);
        button.setAttribute("title", originalTitle);
        button.classList.remove("copy-success");
      }, 1500);
    } else if (button) {
      const original = button.dataset.originalText || button.textContent;
      button.dataset.originalText = original;
      button.textContent = copied ? "Copied!" : "Copy failed";
      setTimeout(() => {
        button.textContent = original;
      }, 1500);
    }

    return copied;
  };

  document.addEventListener("click", (event) => {
    const button = event.target.closest("button[data-copy]");
    if (!button) return;
    window.cornqCopyText(button.dataset.copy, button);
  });
})();
