(function () {
  const TOKEN_KEY = "campus_suite_admin_token";
  const API_BASE = "/api/v1";
  const MASTER_TABS = ["Courses", "Classes", "Sections", "Documents"];
  const EXTRA_TABS = ["Setup overview"];
  let activeTab = "Courses";
  let mastersCache = null;

  function ensureStyle() {
    if (document.getElementById("roseland-master-polish-style")) return;
    const style = document.createElement("style");
    style.id = "roseland-master-polish-style";
    style.textContent = `
      [data-roseland-master-page] .overflow-hidden,
      [data-roseland-master-page] .overflow-x-auto,
      [data-roseland-master-page] .overflow-y-auto { overflow: visible !important; }
      [data-roseland-master-manager] { position: relative; z-index: 20; }
      [data-roseland-master-manager] table { overflow: visible; }
      [data-roseland-master-manager] .roseland-action-row { position: relative; z-index: 30; overflow: visible; }
      [data-roseland-master-manager] .roseland-action-menu { position: absolute; right: 0; top: 2.4rem; z-index: 9999; min-width: 10rem; }
      [data-roseland-master-page] [role="menu"],
      [data-roseland-master-page] [data-radix-popper-content-wrapper],
      [data-roseland-master-page] [class*="z-"],
      [data-roseland-master-page] [class*="absolute"][class*="right"] { z-index: 99999 !important; }
      [data-roseland-hidden="true"] { display: none !important; }
    `;
    document.head.appendChild(style);
  }

  function cleanText(node) {
    return (node && node.textContent ? node.textContent : "").replace(/\s+/g, " ").trim();
  }

  function isMasterPage() {
    return Array.from(document.querySelectorAll("h1")).some((node) => cleanText(node) === "Master entries");
  }

  function isSubjectSetupPage() {
    return Array.from(document.querySelectorAll("h1,h2")).some((node) => /Subject setup|Subject registration master/i.test(cleanText(node)));
  }

  function rootNode() {
    const h1 = Array.from(document.querySelectorAll("h1")).find((node) => cleanText(node) === "Master entries");
    return h1 ? (h1.closest("main") || h1.closest("#root") || document.getElementById("root")) : null;
  }

  function closestCard(node) {
    let current = node;
    while (current && current !== document.body) {
      const classes = String(current.className || "");
      if ((classes.includes("rounded") && classes.includes("border")) || current.tagName === "SECTION") {
        return current;
      }
      current = current.parentElement;
    }
    return node;
  }

  function findCardByText(root, matcher) {
    const nodes = Array.from(root.querySelectorAll("h2,h3,p,div,span"));
    const found = nodes.find((node) => matcher(cleanText(node)));
    return found ? closestCard(found) : null;
  }

  function getMasterButtons(root) {
    return Array.from(root.querySelectorAll("button")).filter((button) => MASTER_TABS.includes(cleanText(button)));
  }

  function ensureTab(buttonBar, label) {
    let button = Array.from(buttonBar.querySelectorAll("button")).find((item) => cleanText(item) === label);
    if (button) return button;
    button = document.createElement("button");
    button.type = "button";
    button.textContent = label;
    button.dataset.roselandMasterTab = label;
    button.className = "rounded-xl border border-slate-800 bg-slate-900/55 p-3 text-left text-sm text-slate-300 hover:border-cyan-400/40";
    button.addEventListener("click", () => {
      activeTab = label;
      refreshLayout();
    });
    buttonBar.appendChild(button);
    return button;
  }

  function setButtonState(button, active) {
    button.className = active
      ? "rounded-xl border border-cyan-400/50 bg-cyan-500/10 p-3 text-left text-sm font-semibold text-white shadow-lg shadow-cyan-950/20"
      : "rounded-xl border border-slate-800 bg-slate-900/55 p-3 text-left text-sm text-slate-300 hover:border-cyan-400/40";
  }

  async function fetchMasters() {
    if (mastersCache) return mastersCache;
    const token = localStorage.getItem(TOKEN_KEY);
    if (!token) throw new Error("Login token not found. Please login again.");
    const response = await fetch(`${API_BASE}/admin/erp/masters`, {
      headers: { Authorization: `Bearer ${token}` },
    });
    if (!response.ok) throw new Error(`Unable to load masters (${response.status})`);
    const payload = await response.json();
    mastersCache = payload.data || {};
    return mastersCache;
  }

  async function saveCourse(payload) {
    const token = localStorage.getItem(TOKEN_KEY);
    if (!token) throw new Error("Login token not found. Please login again.");
    const response = await fetch(`${API_BASE}/admin/erp/masters/courses`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Authorization: `Bearer ${token}` },
      body: JSON.stringify(payload),
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.error || `Unable to save course (${response.status})`);
    mastersCache = null;
    return data.data;
  }

  async function deleteCourse(id) {
    const token = localStorage.getItem(TOKEN_KEY);
    if (!token) throw new Error("Login token not found. Please login again.");
    const response = await fetch(`${API_BASE}/admin/erp/masters/courses/${encodeURIComponent(id)}`, {
      method: "DELETE",
      headers: { Authorization: `Bearer ${token}` },
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.error || `Unable to delete course (${response.status})`);
    mastersCache = null;
    return data.data;
  }

  async function saveClass(payload) {
    const token = localStorage.getItem(TOKEN_KEY);
    if (!token) throw new Error("Login token not found. Please login again.");
    const response = await fetch(`${API_BASE}/admin/erp/masters/classes`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Authorization: `Bearer ${token}` },
      body: JSON.stringify(payload),
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.error || `Unable to save class (${response.status})`);
    mastersCache = null;
    return data.data;
  }

  async function deleteClass(id) {
    const token = localStorage.getItem(TOKEN_KEY);
    if (!token) throw new Error("Login token not found. Please login again.");
    const response = await fetch(`${API_BASE}/admin/erp/masters/classes/${encodeURIComponent(id)}`, {
      method: "DELETE",
      headers: { Authorization: `Bearer ${token}` },
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.error || `Unable to delete class (${response.status})`);
    mastersCache = null;
    return data.data;
  }

  async function saveAcademicYear(form) {
    const token = localStorage.getItem(TOKEN_KEY);
    if (!token) throw new Error("Login token not found. Please login again.");
    const payload = {
      id: form.querySelector("[name='academicYearId']")?.value || "",
      name: form.querySelector("[name='academicYearName']")?.value || "",
      startsOn: form.querySelector("[name='academicYearStartsOn']")?.value || "",
      endsOn: form.querySelector("[name='academicYearEndsOn']")?.value || "",
      isActive: true,
    };
    const response = await fetch(`${API_BASE}/admin/erp/masters/academic-years`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Authorization: `Bearer ${token}`,
      },
      body: JSON.stringify(payload),
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.error || `Unable to save academic year (${response.status})`);
    mastersCache = null;
    return data.data;
  }

  function valueOrDash(value) {
    return value === undefined || value === null || value === "" ? "—" : String(value);
  }

  function summaryCard(label, value, note) {
    return `
      <article class="rounded-2xl border border-slate-800 bg-slate-950/50 p-4">
        <p class="text-xs font-bold uppercase tracking-wide text-slate-500">${label}</p>
        <p class="mt-2 text-2xl font-black text-white">${valueOrDash(value)}</p>
        ${note ? `<p class="mt-1 text-xs text-slate-400">${note}</p>` : ""}
      </article>
    `;
  }

  function rows(items) {
    return items.map(([label, value]) => `
      <tr class="border-b border-slate-800/70">
        <th class="py-2 pr-4 text-left text-xs uppercase tracking-wide text-slate-500">${label}</th>
        <td class="py-2 text-sm font-semibold text-slate-100">${valueOrDash(value)}</td>
      </tr>
    `).join("");
  }

  function escapeHtml(value) {
    return String(value ?? "").replace(/[&<>"']/g, (char) => ({
      "&": "&amp;",
      "<": "&lt;",
      ">": "&gt;",
      '"': "&quot;",
      "'": "&#039;",
    }[char]));
  }

  function managerShell(title, body) {
    return `
      <section class="mt-4 rounded-2xl border border-cyan-500/25 bg-slate-950/75 p-5 shadow-2xl shadow-slate-950/20" data-roseland-master-manager>
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 class="font-display text-xl font-semibold text-white">${title}</h2>
            <p class="mt-1 text-sm text-slate-400">Use this Roseland panel for add, update and delete. Edit fills the form below; Save updates the same record.</p>
          </div>
          <button type="button" class="admin-btn-ghost text-xs" data-roseland-manager-refresh>Refresh</button>
        </div>
        ${body}
        <p class="mt-3 text-xs font-semibold text-slate-400" data-roseland-manager-message></p>
      </section>
    `;
  }

  async function renderCoursesManager(panel) {
    const data = await fetchMasters();
    const courses = (data.courses || []).filter((course) => !/b\.?\s*a\.?|bachelor of arts|b\.?\s*sc|bachelor of science/i.test(course.course || ""));
    panel.innerHTML = managerShell("Roseland course manager", `
      <form class="mt-5 grid gap-3 rounded-xl border border-slate-800 bg-slate-900/50 p-4 md:grid-cols-[1fr_150px_1.4fr_auto]" data-roseland-course-form>
        <input type="hidden" name="id">
        <input class="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm font-semibold text-white" name="course" placeholder="Course name" required>
        <input class="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm font-semibold text-white" name="shortName" placeholder="Short name">
        <input class="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm font-semibold text-white" name="notes" placeholder="Notes">
        <button type="submit" class="admin-btn-primary text-xs" data-roseland-course-save>Save Course</button>
        <button type="button" class="admin-btn-ghost text-xs" data-roseland-course-cancel>New</button>
      </form>
      <div class="mt-5 overflow-visible">
        <table class="w-full text-left text-sm">
          <thead><tr class="border-b border-slate-800 text-xs uppercase tracking-wide text-slate-500"><th class="py-2">Course</th><th>Short</th><th>Notes</th><th class="text-right">Actions</th></tr></thead>
          <tbody>${courses.map((course) => `
            <tr class="roseland-action-row border-b border-slate-800/70" data-course-id="${escapeHtml(course.id)}">
              <td class="py-3 font-semibold text-white">${escapeHtml(course.course)}</td>
              <td class="py-3 text-slate-300">${escapeHtml(course.shortName)}</td>
              <td class="py-3 text-slate-400">${escapeHtml(course.notes)}</td>
              <td class="py-3 text-right">
                <button type="button" class="admin-btn-ghost text-xs" data-edit-course="${escapeHtml(course.id)}">Edit</button>
                <button type="button" class="admin-btn-ghost text-xs text-rose-200" data-delete-course="${escapeHtml(course.id)}">Delete</button>
              </td>
            </tr>`).join("") || `<tr><td colspan="4" class="py-5 text-center text-slate-400">No Roseland courses found.</td></tr>`}</tbody>
        </table>
      </div>
    `);
    const form = panel.querySelector("[data-roseland-course-form]");
    const message = panel.querySelector("[data-roseland-manager-message]");
    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      try {
        if (message) message.textContent = "Saving course...";
        await saveCourse({
          id: form.elements.namedItem("id").value,
          course: form.elements.namedItem("course").value,
          shortName: form.elements.namedItem("shortName").value,
          notes: form.elements.namedItem("notes").value,
        });
        if (message) message.textContent = "Course saved.";
        await renderCoursesManager(panel);
      } catch (error) {
        if (message) message.textContent = error.message;
      }
    });
    panel.querySelectorAll("[data-edit-course]").forEach((button) => button.addEventListener("click", () => {
      const course = courses.find((item) => String(item.id) === String(button.dataset.editCourse));
      if (!course) return;
      form.elements.namedItem("id").value = course.id || "";
      form.elements.namedItem("course").value = course.course || "";
      form.elements.namedItem("shortName").value = course.shortName || "";
      form.elements.namedItem("notes").value = course.notes || "";
      const save = panel.querySelector("[data-roseland-course-save]");
      if (save) save.textContent = "Update Course";
      if (message) message.textContent = `Editing ${course.course}. Change details and click Update Course.`;
      form.elements.namedItem("course").focus();
    }));
    panel.querySelector("[data-roseland-course-cancel]")?.addEventListener("click", () => {
      form.reset();
      form.elements.namedItem("id").value = "";
      const save = panel.querySelector("[data-roseland-course-save]");
      if (save) save.textContent = "Save Course";
      if (message) message.textContent = "Ready to add a new course.";
    });
    panel.querySelectorAll("[data-delete-course]").forEach((button) => button.addEventListener("click", async () => {
      const course = courses.find((item) => String(item.id) === String(button.dataset.deleteCourse));
      if (!course || !window.confirm(`Delete course "${course.course}"?`)) return;
      try {
        if (message) message.textContent = "Deleting course...";
        await deleteCourse(course.id);
        await renderCoursesManager(panel);
      } catch (error) {
        if (message) message.textContent = error.message;
      }
    }));
    panel.querySelector("[data-roseland-manager-refresh]")?.addEventListener("click", async () => {
      mastersCache = null;
      await renderCoursesManager(panel);
    });
  }

  async function renderClassesManager(panel) {
    const data = await fetchMasters();
    const courses = (data.courses || []).filter((course) => !/b\.?\s*a\.?|bachelor of arts|b\.?\s*sc|bachelor of science/i.test(course.course || ""));
    const classes = data.classes || [];
    panel.innerHTML = managerShell("Roseland class manager", `
      <form class="mt-5 grid gap-3 rounded-xl border border-slate-800 bg-slate-900/50 p-4 md:grid-cols-[1fr_1fr_130px_auto]" data-roseland-class-form>
        <input type="hidden" name="id">
        <input class="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm font-semibold text-white" name="name" placeholder="Class name" required>
        <select class="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm font-semibold text-white" name="course" required>
          <option value="">Select course</option>
          ${courses.map((course) => `<option>${escapeHtml(course.course)}</option>`).join("")}
        </select>
        <input class="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm font-semibold text-white" name="levelOrder" type="number" placeholder="Order" required>
        <button type="submit" class="admin-btn-primary text-xs" data-roseland-class-save>Save Class</button>
        <button type="button" class="admin-btn-ghost text-xs" data-roseland-class-cancel>New</button>
      </form>
      <div class="mt-5 overflow-visible">
        <table class="w-full text-left text-sm">
          <thead><tr class="border-b border-slate-800 text-xs uppercase tracking-wide text-slate-500"><th class="py-2">Class</th><th>Course</th><th>Order</th><th class="text-right">Actions</th></tr></thead>
          <tbody>${classes.map((item) => `
            <tr class="roseland-action-row border-b border-slate-800/70" data-class-id="${escapeHtml(item.id)}">
              <td class="py-3 font-semibold text-white">${escapeHtml(item.name)}</td>
              <td class="py-3 text-slate-300">${escapeHtml(item.course)}</td>
              <td class="py-3 text-slate-400">${escapeHtml(item.level_order)}</td>
              <td class="py-3 text-right">
                <button type="button" class="admin-btn-ghost text-xs" data-edit-class="${escapeHtml(item.id)}">Edit</button>
                <button type="button" class="admin-btn-ghost text-xs text-rose-200" data-delete-class="${escapeHtml(item.id)}">Delete</button>
              </td>
            </tr>`).join("") || `<tr><td colspan="4" class="py-5 text-center text-slate-400">No classes found.</td></tr>`}</tbody>
        </table>
      </div>
    `);
    const form = panel.querySelector("[data-roseland-class-form]");
    const message = panel.querySelector("[data-roseland-manager-message]");
    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      try {
        if (message) message.textContent = "Saving class...";
        await saveClass({
          id: form.elements.namedItem("id").value,
          name: form.elements.namedItem("name").value,
          course: form.elements.namedItem("course").value,
          levelOrder: form.elements.namedItem("levelOrder").value,
        });
        if (message) message.textContent = "Class saved.";
        await renderClassesManager(panel);
      } catch (error) {
        if (message) message.textContent = error.message;
      }
    });
    panel.querySelectorAll("[data-edit-class]").forEach((button) => button.addEventListener("click", () => {
      const item = classes.find((row) => String(row.id) === String(button.dataset.editClass));
      if (!item) return;
      form.elements.namedItem("id").value = item.id || "";
      form.elements.namedItem("name").value = item.name || "";
      form.elements.namedItem("course").value = item.course || "";
      form.elements.namedItem("levelOrder").value = item.level_order || "";
      const save = panel.querySelector("[data-roseland-class-save]");
      if (save) save.textContent = "Update Class";
      if (message) message.textContent = `Editing ${item.name}. Change details and click Update Class.`;
      form.elements.namedItem("name").focus();
    }));
    panel.querySelector("[data-roseland-class-cancel]")?.addEventListener("click", () => {
      form.reset();
      form.elements.namedItem("id").value = "";
      const save = panel.querySelector("[data-roseland-class-save]");
      if (save) save.textContent = "Save Class";
      if (message) message.textContent = "Ready to add a new class.";
    });
    panel.querySelectorAll("[data-delete-class]").forEach((button) => button.addEventListener("click", async () => {
      const item = classes.find((row) => String(row.id) === String(button.dataset.deleteClass));
      if (!item || !window.confirm(`Delete class "${item.name}"?`)) return;
      try {
        if (message) message.textContent = "Deleting class...";
        await deleteClass(item.id);
        await renderClassesManager(panel);
      } catch (error) {
        if (message) message.textContent = error.message;
      }
    }));
    panel.querySelector("[data-roseland-manager-refresh]")?.addEventListener("click", async () => {
      mastersCache = null;
      await renderClassesManager(panel);
    });
  }

  function ensureManagerPanel(root, buttonBar) {
    let panel = root.querySelector("[data-roseland-course-class-manager]");
    if (panel) return panel;
    panel = document.createElement("div");
    panel.dataset.roselandCourseClassManager = "true";
    panel.style.display = "none";
    buttonBar.insertAdjacentElement("afterend", panel);
    return panel;
  }

  async function renderOverview(panel) {
    panel.innerHTML = `<div class="rounded-2xl border border-slate-800 bg-slate-950/45 p-6 text-slate-300">Loading setup overview…</div>`;
    try {
      const data = await fetchMasters();
      const institution = data.institution || {};
      const activeYear = (data.academicYears || []).find((year) => String(year.is_active) === "1") || (data.academicYears || [])[0] || {};
      const communication = data.communicationSettings || {};
      panel.innerHTML = `
        <section class="rounded-2xl border border-slate-800 bg-slate-950/45 p-6">
          <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
              <h2 class="font-display text-xl font-semibold text-white">Setup overview</h2>
              <p class="mt-1 text-sm text-slate-400">Only working and useful master data is shown here for Roseland School.</p>
            </div>
            <button type="button" class="admin-btn-ghost text-xs" data-roseland-refresh>Refresh</button>
          </div>
          <div class="mt-5 grid gap-3 md:grid-cols-4">
            ${summaryCard("Courses", (data.courses || []).length, "Pre-primary to higher secondary")}
            ${summaryCard("Classes", (data.classes || []).length, "Nursery to Class XII")}
            ${summaryCard("Documents", (data.documents || []).length, "Admission checklist")}
            ${summaryCard("Students", (data.students || []).length, "Existing students")}
          </div>
          <div class="mt-6 grid gap-4 xl:grid-cols-3">
            <div class="rounded-xl border border-slate-800 bg-slate-900/45 p-4">
              <h3 class="font-semibold text-white">Institute details</h3>
              <table class="mt-3 w-full">${rows([
                ["Name", institution.name],
                ["Code", institution.code],
                ["Type", institution.type],
                ["Email", institution.email],
                ["Phone", institution.phone],
                ["Address", institution.address],
              ])}</table>
            </div>
            <div class="rounded-xl border border-slate-800 bg-slate-900/45 p-4">
              <h3 class="font-semibold text-white">Academic year</h3>
              <table class="mt-3 w-full">${rows([
                ["Active year", activeYear.name],
                ["Starts on", activeYear.starts_on],
                ["Ends on", activeYear.ends_on],
                ["Total years", (data.academicYears || []).length],
              ])}</table>
              <form class="mt-4 grid gap-2" data-roseland-academic-year-form>
                <input type="hidden" name="academicYearId" value="${activeYear.id || ""}">
                <label class="text-xs font-bold uppercase tracking-wide text-slate-500">Academic year name</label>
                <input class="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm font-semibold text-white" name="academicYearName" value="${activeYear.name || ""}" placeholder="2026-27" required>
                <div class="grid gap-2 md:grid-cols-2">
                  <label class="text-xs font-bold uppercase tracking-wide text-slate-500">Start date<input class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm font-semibold text-white" type="date" name="academicYearStartsOn" value="${activeYear.starts_on || ""}" required></label>
                  <label class="text-xs font-bold uppercase tracking-wide text-slate-500">End date<input class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm font-semibold text-white" type="date" name="academicYearEndsOn" value="${activeYear.ends_on || ""}" required></label>
                </div>
                <button type="submit" class="admin-btn-primary mt-1 text-xs">Save active academic year</button>
                <p class="text-xs text-slate-500" data-roseland-academic-year-message>Admission forms use this active year automatically.</p>
              </form>
            </div>
            <div class="rounded-xl border border-slate-800 bg-slate-900/45 p-4">
              <h3 class="font-semibold text-white">Communication settings</h3>
              <table class="mt-3 w-full">${rows([
                ["Enabled", communication.isEnabled ? "Yes" : "No"],
                ["From name", communication.fromName],
                ["From email", communication.fromEmail],
                ["SMTP host", communication.smtpHost],
                ["SMTP user", communication.smtpUsername],
              ])}</table>
            </div>
          </div>
        </section>
      `;
      const refresh = panel.querySelector("[data-roseland-refresh]");
      if (refresh) refresh.addEventListener("click", () => {
        mastersCache = null;
        renderOverview(panel);
      });
      const yearForm = panel.querySelector("[data-roseland-academic-year-form]");
      if (yearForm) yearForm.addEventListener("submit", async (event) => {
        event.preventDefault();
        const message = panel.querySelector("[data-roseland-academic-year-message]");
        try {
          if (message) message.textContent = "Saving academic year...";
          await saveAcademicYear(yearForm);
          if (message) message.textContent = "Academic year saved and set active.";
          await renderOverview(panel);
        } catch (error) {
          if (message) message.textContent = error.message;
        }
      });
    } catch (error) {
      panel.innerHTML = `<div class="rounded-2xl border border-rose-500/30 bg-rose-500/10 p-4 text-sm text-rose-200">${error.message}</div>`;
    }
  }

  function ensureOverviewPanel(root, buttonBar) {
    let panel = root.querySelector("[data-roseland-setup-overview]");
    if (panel) return panel;
    panel = document.createElement("div");
    panel.dataset.roselandSetupOverview = "true";
    panel.style.display = "none";
    buttonBar.insertAdjacentElement("afterend", panel);
    renderOverview(panel);
    return panel;
  }

  function replaceCollegeCopy(root) {
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    const replacements = [
      [/when the college already has admitted students/g, "when the school already has admitted students"],
      [/college office/g, "school office"],
      [/College/g, "School"],
    ];
    const nodes = [];
    while (walker.nextNode()) nodes.push(walker.currentNode);
    nodes.forEach((node) => {
      let next = node.nodeValue;
      replacements.forEach(([pattern, replacement]) => {
        next = next.replace(pattern, replacement);
      });
      if (next !== node.nodeValue) node.nodeValue = next;
    });
  }

  function removeExistingStudentsImport(root) {
    Array.from(root.querySelectorAll("button")).forEach((button) => {
      if (cleanText(button) === "Existing students") {
        button.remove();
      }
    });
    Array.from(root.querySelectorAll("h2,h3,p,div,span"))
      .filter((node) => cleanText(node) === "Existing students import")
      .map((node) => closestCard(node))
      .filter((node, index, list) => node && list.indexOf(node) === index)
      .forEach((card) => card.remove());
  }

  function nearestSection(node) {
    let current = node;
    while (current && current !== document.body) {
      const text = cleanText(current);
      const classes = String(current.className || "");
      if (current.tagName === "SECTION" || (classes.includes("rounded") && classes.includes("border") && text.length > 40)) {
        return current;
      }
      current = current.parentElement;
    }
    return node;
  }

  function polishSubjectSetup() {
    ensureStyle();
    const root = document.getElementById("root") || document.body;
    if (!isSubjectSetupPage()) return;
    Array.from(root.querySelectorAll("button,h2,h3,p,div,span")).forEach((node) => {
      const text = cleanText(node);
      if (/^Subject papers$|Define paper code|paper code|paper name|theory\/practical/i.test(text)) {
        nearestSection(node).dataset.roselandHidden = "true";
      }
    });
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    const nodes = [];
    while (walker.nextNode()) nodes.push(walker.currentNode);
    nodes.forEach((node) => {
      const next = node.nodeValue
        .replace(/Semester\s*1/gi, "Annual")
        .replace(/Semester/gi, "Annual pattern")
        .replace(/semester-wise/gi, "annual");
      if (next !== node.nodeValue) node.nodeValue = next;
    });
  }

  function refreshLayout() {
    if (!isMasterPage()) return;
    ensureStyle();
    const root = rootNode();
    if (!root) return;
    root.dataset.roselandMasterPage = "true";
    const masterButtons = getMasterButtons(root);
    if (!masterButtons.length) return;
    const buttonBar = masterButtons[0].parentElement;
    EXTRA_TABS.forEach((label) => ensureTab(buttonBar, label));
    const overviewPanel = ensureOverviewPanel(root, buttonBar);
    const managerPanel = ensureManagerPanel(root, buttonBar);
    const registerCard = findCardByText(root, (text) => / register$/.test(text));
    const recordsCard = findCardByText(root, (text) => / records$/.test(text));

    replaceCollegeCopy(root);
    removeExistingStudentsImport(root);

    Array.from(buttonBar.querySelectorAll("button")).forEach((button) => {
      const label = cleanText(button);
      if (MASTER_TABS.includes(label)) {
        if (button.dataset.roselandMasterBound !== "true") {
          button.dataset.roselandMasterBound = "true";
          button.addEventListener("click", () => {
            activeTab = label;
            setTimeout(refreshLayout, 80);
          });
        }
      }
      if (MASTER_TABS.includes(label) || EXTRA_TABS.includes(label)) {
        setButtonState(button, label === activeTab);
      }
    });

    const usingRoselandManager = ["Courses", "Classes"].includes(activeTab);
    if (registerCard) registerCard.style.display = MASTER_TABS.includes(activeTab) && !usingRoselandManager ? "" : "none";
    if (recordsCard && !recordsCard.closest("[data-roseland-course-class-manager]")) {
      recordsCard.style.display = usingRoselandManager ? "none" : "";
    }
    overviewPanel.style.display = activeTab === "Setup overview" ? "" : "none";
    managerPanel.style.display = usingRoselandManager ? "" : "none";
    if (activeTab === "Courses" && managerPanel.dataset.renderedFor !== "Courses") {
      managerPanel.dataset.renderedFor = "Courses";
      renderCoursesManager(managerPanel).catch((error) => {
        managerPanel.innerHTML = `<div class="mt-4 rounded-2xl border border-rose-500/30 bg-rose-500/10 p-4 text-sm text-rose-200">${error.message}</div>`;
      });
    }
    if (activeTab === "Classes" && managerPanel.dataset.renderedFor !== "Classes") {
      managerPanel.dataset.renderedFor = "Classes";
      renderClassesManager(managerPanel).catch((error) => {
        managerPanel.innerHTML = `<div class="mt-4 rounded-2xl border border-rose-500/30 bg-rose-500/10 p-4 text-sm text-rose-200">${error.message}</div>`;
      });
    }
  }

  function scheduleRefresh() {
    window.requestAnimationFrame(() => {
      try {
      refreshLayout();
      polishSubjectSetup();
      } catch (error) {
        console.warn("Roseland admin polish failed", error);
      }
    });
  }

  window.addEventListener("popstate", scheduleRefresh);
  window.addEventListener("campus-suite-auth-change", () => {
    mastersCache = null;
    scheduleRefresh();
  });
  document.addEventListener("click", (event) => {
    const target = event.target && event.target.closest ? event.target.closest("a,button") : null;
    if (target) setTimeout(scheduleRefresh, 120);
  });
  new MutationObserver(scheduleRefresh).observe(document.documentElement, { childList: true, subtree: true });
  scheduleRefresh();
})();
