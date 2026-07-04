const loginPanel = document.querySelector("#login-panel");
const dashboardPanel = document.querySelector("#dashboard-panel");
const loginForm = document.querySelector("#login-form");
const contentForm = document.querySelector("#content-form");
const loginMessage = document.querySelector("#login-message");
const saveMessage = document.querySelector("#save-message");
const logoutButton = document.querySelector("#logout-button");

const apiPath = "../api";

const setMessage = (element, text, isError = false) => {
  element.textContent = text;
  element.classList.toggle("error", isError);
};

const showDashboard = () => {
  loginPanel.classList.add("hidden");
  dashboardPanel.classList.remove("hidden");
};

const showLogin = () => {
  dashboardPanel.classList.add("hidden");
  loginPanel.classList.remove("hidden");
};

const loadContent = async () => {
  const response = await fetch(`${apiPath}/content.php`);
  const result = await response.json();

  Object.entries(result.content || {}).forEach(([key, value]) => {
    const field = contentForm.elements[key];
    if (field) {
      field.value = value;
    }
  });
};

const checkSession = async () => {
  const response = await fetch(`${apiPath}/auth.php`);
  const result = await response.json();

  if (result.logged_in) {
    showDashboard();
    await loadContent();
  } else {
    showLogin();
  }
};

loginForm.addEventListener("submit", async (event) => {
  event.preventDefault();
  setMessage(loginMessage, "Logging in...");

  const payload = Object.fromEntries(new FormData(loginForm).entries());
  const response = await fetch(`${apiPath}/auth.php`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(payload)
  });
  const result = await response.json();

  if (!response.ok) {
    setMessage(loginMessage, result.message || "Login failed.", true);
    return;
  }

  setMessage(loginMessage, "");
  showDashboard();
  await loadContent();
});

contentForm.addEventListener("submit", async (event) => {
  event.preventDefault();
  setMessage(saveMessage, "Saving website content...");

  const content = Object.fromEntries(new FormData(contentForm).entries());
  const response = await fetch(`${apiPath}/content.php`, {
    method: "PUT",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ content })
  });
  const result = await response.json();

  setMessage(saveMessage, result.message || "Saved.", !response.ok);
});

logoutButton.addEventListener("click", async () => {
  await fetch(`${apiPath}/auth.php`, { method: "DELETE" });
  showLogin();
});

checkSession().catch(() => {
  showLogin();
  setMessage(loginMessage, "Unable to load CMS. Please try again.", true);
});
