(function () {
  "use strict";

  const byId = (id) => document.getElementById(id);
  const config = byId("shareConfig");
  if (!config) return;

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

  async function getIpInfo(ip) {
    try {
      const response = await fetch(
        config.dataset.infoEndpoint + encodeURIComponent(ip),
        { cache: "no-store" }
      );
      return await response.json();
    } catch (error) {
      return null;
    }
  }

  async function getIx(asn) {
    try {
      const response = await fetch(
        config.dataset.ixEndpoint + encodeURIComponent(asn),
        { cache: "no-store" }
      );
      const data = await response.json();
      return data.data || [];
    } catch (error) {
      return [];
    }
  }

  function formatSpeed(speed) {
    if (speed >= 1000000) return speed / 1000000 + " Tbps";
    if (speed >= 1000) return speed / 1000 + " Gbps";
    return speed + " Mbps";
  }

  function displayInfo(info) {
    if (!info || info.success === false) return;

    const connection = info.connection || {};
    byId("isp").textContent = connection.isp || "-";
    byId("asn").textContent = connection.asn ? "AS" + connection.asn : "-";
    byId("org").textContent = connection.org || "-";
    byId("domain").textContent = connection.domain || "-";
    byId("country").textContent = info.country
      ? info.country + " (" + info.country_code + ")"
      : "-";
    byId("location").textContent =
      [info.city, info.region].filter(Boolean).join(", ") || "-";
    byId("timezone").textContent =
      (info.timezone && (info.timezone.id || info.timezone.utc)) || "-";
  }

  function createCopyButton(ip) {
    const button = document.createElement("button");
    button.className = "btn secondary copy-ip-button";
    button.type = "button";
    button.textContent = "Copy";
    button.addEventListener("click", () => window.cornqCopyText(ip, button));
    return button;
  }

  function createAddressRow(version, ip) {
    const row = document.createElement("div");
    row.className = "ix-ip value-with-copy";

    const address = document.createElement("span");
    address.textContent = version + ": " + ip;
    row.append(address, createCopyButton(ip));
    return row;
  }

  function displayIx(records) {
    const container = byId("ix-list");
    container.classList.remove("loading");
    container.textContent = "";

    if (!records.length) {
      const message = document.createElement("span");
      message.className = "not-available";
      message.textContent = "No IX information found in PeeringDB.";
      container.appendChild(message);
      return;
    }

    records.forEach((ix) => {
      const item = document.createElement("div");
      item.className = "ix-item";

      const name = document.createElement("div");
      name.className = "ix-name";
      name.textContent = ix.name || "IX #" + (ix.ix_id || "");
      item.appendChild(name);

      if (ix.ipaddr4) item.appendChild(createAddressRow("IPv4", ix.ipaddr4));
      if (ix.ipaddr6) item.appendChild(createAddressRow("IPv6", ix.ipaddr6));

      if (ix.speed) {
        const speed = document.createElement("div");
        speed.className = "label port-speed";
        speed.textContent = "Port: " + formatSpeed(ix.speed);
        item.appendChild(speed);
      }

      container.appendChild(item);
    });
  }

  let detectedIpv4 = null;
  let detectedIpv6 = null;
  let generatedShareUrl = "";

  async function loadNetworkDetails() {
    const [ipv4, ipv6] = await Promise.all([
      detect("https://api.ipify.org?format=json"),
      detect("https://api6.ipify.org?format=json"),
    ]);

    detectedIpv4 = ipv4;
    detectedIpv6 = ipv6;
    byId("ipv4").textContent = ipv4 || "Not available";
    byId("ipv4").classList.remove("loading");
    byId("ipv6").textContent = ipv6 || "No IPv6 detected";
    byId("ipv6").classList.remove("loading");
    byId("copyIpv6").disabled = !ipv6;

    const lookupIp = ipv4 || ipv6;
    if (!lookupIp) {
      byId("ix-list").textContent = "Unable to detect connection.";
      return;
    }

    byId("shareLinkBtn").disabled = false;
    const info = await getIpInfo(lookupIp);
    displayInfo(info);

    if (info && info.connection && info.connection.asn) {
      displayIx(await getIx(info.connection.asn));
    } else {
      byId("ix-list").textContent = "ASN unavailable.";
    }
  }

  byId("copyIp").addEventListener("click", function () {
    const ip = byId("ipv4").textContent.trim();
    if (ip && ip !== "Detecting..." && ip !== "Not available") {
      window.cornqCopyText(ip, this);
    }
  });

  byId("copyIpv6").addEventListener("click", function () {
    const ip = byId("ipv6").textContent.trim();
    if (ip && ip !== "Detecting..." && ip !== "No IPv6 detected") {
      window.cornqCopyText(ip, this);
    }
  });

  const shareModal = byId("shareModal");
  const shareModalPanel = byId("shareModalPanel");

  function openShareModal() {
    shareModal.hidden = false;
    byId("shareError").hidden = true;
    byId("shareExpiry").focus();
  }

  function closeShareModal() {
    shareModal.hidden = true;
  }

  function shakeShareModal() {
    shareModalPanel.classList.remove("modal-shake");
    void shareModalPanel.offsetWidth;
    shareModalPanel.classList.add("modal-shake");
  }

  byId("shareLinkBtn").addEventListener("click", function () {
    if (generatedShareUrl) {
      window.cornqCopyText(generatedShareUrl, this);
    } else {
      openShareModal();
    }
  });

  byId("copyShareBox").addEventListener("click", function () {
    if (generatedShareUrl) window.cornqCopyText(generatedShareUrl, this);
  });

  byId("closeShareModal").addEventListener("click", closeShareModal);
  shareModal.addEventListener("click", (event) => {
    if (event.target === shareModal) shakeShareModal();
  });
  shareModalPanel.addEventListener("animationend", () =>
    shareModalPanel.classList.remove("modal-shake")
  );
  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && !shareModal.hidden) {
      event.preventDefault();
      shakeShareModal();
    }
  });

  byId("shareNote").addEventListener("input", function () {
    byId("shareNoteCounter").textContent = this.value.length + " / 500";
  });

  byId("shareForm").addEventListener("submit", async (event) => {
    event.preventDefault();
    const submit = byId("createShareBtn");
    const errorBox = byId("shareError");
    submit.disabled = true;
    submit.textContent = "Generating...";
    errorBox.hidden = true;

    try {
      const response = await fetch(config.dataset.createEndpoint, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          csrf: config.dataset.csrf,
          note: byId("shareNote").value,
          expiry_days: byId("shareExpiry").value,
          ipv4: detectedIpv4,
          ipv6: detectedIpv6,
        }),
      });
      const data = await response.json();
      if (!response.ok || !data.success) {
        throw new Error(data.message || "Unable to create sharable link.");
      }

      generatedShareUrl = data.share_url;
      byId("shareUrlBox").textContent = generatedShareUrl;
      byId("shareOutput").hidden = false;
      byId("shareLinkBtn").textContent = "Copy Shareable Link";
      closeShareModal();
    } catch (error) {
      errorBox.textContent = String(error.message || error);
      errorBox.hidden = false;
    } finally {
      submit.disabled = false;
      submit.textContent = "Generate Sharable Link";
    }
  });

  if (new URLSearchParams(window.location.search).get("generate") === "1") {
    openShareModal();
    window.history.replaceState({}, "", window.location.pathname);
  }

  loadNetworkDetails();
})();
