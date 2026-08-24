(function () {
  "use strict";

  const config = document.getElementById("diagnosticConfig");
  const button = document.getElementById("shareBtn");
  const statusBox = document.getElementById("status");

  if (!config || !button || !statusBox) return;

  async function detect(url, timeout = 6000) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeout);

    try {
      const response = await fetch(url, {
        signal: controller.signal,
        cache: "no-store",
      });
      if (!response.ok) throw new Error();
      return (await response.json()).ip || null;
    } catch (error) {
      return null;
    } finally {
      clearTimeout(timer);
    }
  }

  button.addEventListener("click", async () => {
    button.disabled = true;
    statusBox.innerHTML = '<span class="loading">Detecting and sharing network information…</span>';

    const [ipv4, ipv6] = await Promise.all([
      detect("https://api.ipify.org?format=json"),
      detect("https://api6.ipify.org?format=json"),
    ]);

    try {
      const response = await fetch(config.dataset.endpoint, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ ipv4, ipv6 }),
      });
      const data = await response.json();
      if (!response.ok || !data.success) {
        throw new Error(data.message || "Unable to submit diagnostic data.");
      }

      statusBox.innerHTML =
        '<div class="alert success">Network information shared successfully.<br>Reference: <strong>' +
        String(data.reference) +
        "</strong></div>";
      button.style.display = "none";
    } catch (error) {
      statusBox.textContent = "";
      const alert = document.createElement("div");
      alert.className = "alert error";
      alert.textContent = String(error.message || error);
      statusBox.appendChild(alert);
      button.disabled = false;
    }
  });
})();
