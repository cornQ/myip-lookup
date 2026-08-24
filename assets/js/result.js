(function () {
  "use strict";

  const config = document.getElementById("resultConfig");
  const copyButton = document.getElementById("copyReportButton");
  const deleteForm = document.getElementById("deleteReportForm");

  if (!config || !copyButton) return;

  function normalizedText(element) {
    return element ? element.textContent.replace(/\s+/g, " ").trim() : "";
  }

  function formatCapture(capture) {
    const lines = [];
    const title = normalizedText(capture.querySelector("h3"));

    if (title) lines.push(title, "");

    capture.querySelectorAll(".result-table tr").forEach((row) => {
      const cells = row.querySelectorAll("td");
      if (cells.length < 2) return;

      const label = normalizedText(cells[0]);
      const valueElement = cells[1].querySelector(".value-with-copy span");
      const value = normalizedText(valueElement || cells[1]);
      if (label && value) lines.push(label + ": " + value);
    });

    const ixTitle = normalizedText(capture.querySelector("h4"));
    if (ixTitle) lines.push("", ixTitle);

    const ixItems = capture.querySelectorAll(".ix-item");
    if (ixItems.length) {
      ixItems.forEach((item) => {
        const itemLines = [];
        const name = normalizedText(item.querySelector(".ix-name"));
        if (name) itemLines.push(name);

        item.querySelectorAll(".ix-ip span").forEach((address) => {
          const value = normalizedText(address);
          if (value) itemLines.push(value);
        });

        const port = normalizedText(item.querySelector(".port-speed"));
        if (port) itemLines.push(port);
        if (itemLines.length) lines.push("", ...itemLines);
      });
    } else {
      const emptyMessage = normalizedText(capture.querySelector("h4 + .muted"));
      if (emptyMessage) lines.push("", emptyMessage);
    }

    return lines.join("\n").trim();
  }

  copyButton.addEventListener("click", () => {
    const report = document.getElementById("reportText");
    if (!report) return;

    const captures = Array.from(report.querySelectorAll(".capture"))
      .map(formatCapture)
      .filter(Boolean);
    const text = [
      "MyIP Network Diagnostic",
      "Reference: " + config.dataset.reference,
      "",
      captures.join("\n\n"),
    ]
      .join("\n")
      .trim();

    window.cornqCopyText(text, copyButton);
  });

  if (deleteForm) {
    deleteForm.addEventListener("submit", (event) => {
      if (!window.confirm("Delete this report permanently?")) {
        event.preventDefault();
      }
    });
  }
})();
