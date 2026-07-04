const menuToggle = document.querySelector(".menu-toggle");
const menu = document.querySelector("#site-menu");
const form = document.querySelector("#admission-form");
const message = document.querySelector("#form-message");

document.querySelector("#year").textContent = new Date().getFullYear();

const applyCmsContent = (content) => {
  Object.entries(content).forEach(([key, value]) => {
    document.querySelectorAll(`[data-cms="${key}"]`).forEach((element) => {
      element.textContent = value;
    });
  });

  document.querySelectorAll("[data-cms-email-link]").forEach((element) => {
    element.href = `mailto:${element.textContent.trim()}`;
  });

  document.querySelectorAll("[data-cms-phone-link]").forEach((element) => {
    const phone = (content.school_phone || element.textContent).replace(/\D/g, "");
    if (phone) {
      element.href = `tel:${phone}`;
    }
  });
};

fetch("api/content.php")
  .then((response) => response.ok ? response.json() : null)
  .then((result) => {
    if (result?.content) {
      applyCmsContent(result.content);
    }
  })
  .catch(() => {});

menuToggle.addEventListener("click", () => {
  const isOpen = menu.classList.toggle("open");
  menuToggle.setAttribute("aria-expanded", String(isOpen));
});

menu.addEventListener("click", (event) => {
  if (event.target.tagName === "A") {
    menu.classList.remove("open");
    menuToggle.setAttribute("aria-expanded", "false");
  }
});

form.addEventListener("submit", async (event) => {
  event.preventDefault();
  message.className = "form-message";
  message.textContent = "Submitting admission enquiry...";

  const formData = new FormData(form);
  const payload = Object.fromEntries(formData.entries());
  payload.declaration = formData.has("declaration");

  try {
    const response = await fetch("api/admissions.php", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "Accept": "application/json"
      },
      body: JSON.stringify(payload)
    });

    const result = await response.json();

    if (!response.ok) {
      throw new Error(result.message || "Unable to submit the form.");
    }

    form.reset();
    message.classList.add("success");
    message.textContent = result.message || "Admission enquiry submitted successfully.";
  } catch (error) {
    message.classList.add("error");
    message.textContent = error.message || "Please try again or contact the school office.";
  }
});
