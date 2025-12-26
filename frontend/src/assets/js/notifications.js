// 🔔 Universal Notification System (Pure JS) + Reliable Sound Alert
(() => {
  const API_BASE = "/RemoteTeamPro/backend/api/notifications";
  const INTERVAL = 5000; // check every 5 seconds

  const userRole =
    sessionStorage.getItem("user_role") ||
    document.body.dataset.role ||
    "Client";

  const container = document.createElement("div");
  container.id = "notifContainer";
  container.style.position = "fixed";
  container.style.top = "20px";
  container.style.right = "20px";
  container.style.zIndex = "9999";
  container.style.display = "flex";
  container.style.flexDirection = "column";
  container.style.gap = "10px";
  document.body.appendChild(container);

  // 🔊 Prepare sound (browser-safe)
  const notifSound = new Audio(`${window.location.origin}/RemoteTeamPro/frontend/src/assets/sounds/notify.mp3`);


  notifSound.volume = 0.5;
  let soundEnabled = false;

  // Enable sound on first user action (required by Chrome/Edge/Safari)
  const enableSound = () => {
    notifSound.play().then(() => {
      notifSound.pause();
      notifSound.currentTime = 0;
      soundEnabled = true;
      window.removeEventListener("click", enableSound);
      window.removeEventListener("keydown", enableSound);
    });
  };
  window.addEventListener("click", enableSound);
  window.addEventListener("keydown", enableSound);

  function showNotif(n) {
    const box = document.createElement("div");
    box.style.background = "#111";
    box.style.color = "white";
    box.style.border = "1px solid #7c3aed";
    box.style.padding = "10px 14px";
    box.style.borderRadius = "10px";
    box.style.cursor = "pointer";
    box.style.transition = "all 0.3s";
    box.style.boxShadow = "0 4px 10px rgba(0,0,0,0.4)";
    box.style.maxWidth = "280px";
    box.style.fontFamily = "system-ui, sans-serif";
    box.innerHTML = `
      <div style="font-weight:bold;color:#a78bfa;">${n.title}</div>
      <div style="font-size:0.9rem;">${n.body}</div>
      <div style="font-size:0.7rem;opacity:0.7;">${new Date(
        n.created_at
      ).toLocaleTimeString()}</div>
    `;

    box.addEventListener("click", async () => {
      box.style.opacity = "0";
      box.style.transform = "translateX(20px)";
      setTimeout(() => box.remove(), 300);
      await fetch(`${API_BASE}/mark_read.php`, {
        method: "POST",
        credentials: "include",
      });
    });

    container.appendChild(box);
    setTimeout(() => {
      box.style.opacity = "0";
      box.style.transform = "translateX(20px)";
      setTimeout(() => box.remove(), 300);
    }, 7000);
  }

  async function getNotifs() {
    try {
      const res = await fetch(`${API_BASE}/fetch.php`, {
        credentials: "include",
      });
      const data = await res.json();
      if (data.success && data.notifications?.length > 0) {
        // 🔊 Play only once per batch, after user interaction allowed it
        if (soundEnabled) {
          notifSound.currentTime = 0;
          notifSound.play().catch(() => {});
        }

        data.notifications.forEach(showNotif);
        await fetch(`${API_BASE}/mark_read.php`, {
          method: "POST",
          credentials: "include",
        });
      }
    } catch (err) {
      console.error("Notification fetch failed:", err);
    }
  }

  getNotifs();
  setInterval(getNotifs, INTERVAL);
})();
document.addEventListener("DOMContentLoaded", () => {
  const notifBtn = document.getElementById("notifBtn");
  if (notifBtn) {
    notifBtn.addEventListener("click", () => {
      // open modal or fetch notifications
      console.log("Bell icon clicked!");
    });
  }
});
