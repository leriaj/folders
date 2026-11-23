(() => {
  "use strict";

  const root = document.getElementById("root");
  const SESSION_LENGTH = 30 * 60 * 1000;
  const THEME_KEY = "storage-theme";
  const icons = {image:"🖼️",video:"🎬",document:"📄",other:"📎"};
  let data = null;
  let guestCsrf = "";
  let activeNav = "home";
  let currentFolderId = null;
  let searchQuery = "";
  let sessionTimer;
  let theme = localStorage.getItem(THEME_KEY) === "dark" ? "dark" : "light";

  const escapeHTML = value => String(value ?? "").replace(/[&<>"']/g, char => ({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[char]));
  const initials = name => (name || "?").trim().split(/\s+/).slice(0,2).map(part => part[0]).join("").toUpperCase();
  const me = () => data && data.me;
  const userById = id => data?.users.find(user => String(user.id) === String(id));
  const ownedFolders = () => data.folders.filter(folder => String(folder.ownerId) === String(me().id));
  const permittedFolders = () => data.folders.filter(folder => String(folder.ownerId) !== String(me().id) && folder.permissions[me().id]);
  const allVisibleFolders = () => [...ownedFolders(), ...permittedFolders()];
  const pendingRequests = () => data.requests.filter(request => String(request.toId) === String(me().id));
  const bytesText = bytes => {
    if (!bytes) return "0 B";
    const units = ["B","KB","MB","GB"];
    let size = bytes, index = 0;
    while (size >= 1024 && index < units.length - 1) { size /= 1024; index++; }
    return size.toFixed(index ? 1 : 0) + " " + units[index];
  };
  const dateText = value => new Date(value).toLocaleDateString(undefined,{month:"short",day:"numeric",year:"numeric"});
  const relativeTime = value => {
    const difference = Date.now() - value;
    if (difference < 60000) return "just now";
    if (difference < 3600000) return Math.floor(difference / 60000) + " min ago";
    if (difference < 86400000) return Math.floor(difference / 3600000) + " hr ago";
    return dateText(value);
  };

  async function request(action, payload = null, method = "POST") {
    const options = {method,credentials:"same-origin",headers:{}};
    if (method !== "GET") {
      if (payload instanceof FormData) {
        options.body = payload;
      } else {
        options.headers["Content-Type"] = "application/json";
        options.body = JSON.stringify(payload || {});
      }
      const csrf = data?.csrf || guestCsrf;
      if (csrf) options.headers["X-CSRF-Token"] = csrf;
    }
    const response = await fetch("api.php?action=" + encodeURIComponent(action),options);
    let result;
    try { result = await response.json(); }
    catch (error) { throw new Error("The server returned an unreadable response. Check that Apache and PHP are running."); }
    if (!response.ok) {
      if (response.status === 401 && data) {
        data = null;
        clearTimeout(sessionTimer);
        renderAuth();
      }
      const error = new Error(result.error || "The request could not be completed.");
      error.status = response.status;
      throw error;
    }
    if (result.data) data = result.data;
    if (result.csrf) guestCsrf = result.csrf;
    return result;
  }

  function toggleTheme() {
    theme = theme === "dark" ? "light" : "dark";
    localStorage.setItem(THEME_KEY,theme);
    document.documentElement.dataset.theme = theme;
    updateThemeButtons();
  }

  function updateThemeButtons() {
    const label = "Switch to " + (theme === "dark" ? "light" : "dark") + " mode";
    document.querySelectorAll("#theme-toggle, #auth-theme-toggle").forEach(button => {
      button.textContent = theme === "dark" ? "☀" : "☾";
      button.title = label;
      button.setAttribute("aria-label",label);
    });
  }

  function syncRouteFromLocation() {
    const hash = location.hash.slice(1);
    const routes = {overview:"home",folders:"my-folders",shared:"shared",friends:"friends",activity:"activity",admin:"admin"};
    const folderMatch = hash.match(/^folder\/(\d+)$/);
    if (folderMatch) {
      const folder = data?.folders.find(item => String(item.id) === folderMatch[1]);
      if (folder) {
        currentFolderId = String(folder.id);
        activeNav = String(folder.ownerId) === String(me().id) ? "my-folders" : "shared";
        return;
      }
      currentFolderId = null;
      activeNav = "my-folders";
      history.replaceState(null,"","#folders");
      return;
    }
    currentFolderId = null;
    activeNav = routes[hash] || "home";
    const normalizedHash = activeNav === "home" ? "overview" : Object.keys(routes).find(route => routes[route] === activeNav);
    if (location.hash !== "#" + normalizedHash) history.replaceState(null,"","#" + normalizedHash);
  }

  function navigate(route, folderId = null, replace = false) {
    const routes = {home:"overview","my-folders":"folders",shared:"shared",friends:"friends",activity:"activity",admin:"admin"};
    currentFolderId = folderId === null ? null : String(folderId);
    if (currentFolderId) {
      const folder = data.folders.find(item => String(item.id) === currentFolderId);
      activeNav = folder && String(folder.ownerId) !== String(me().id) ? "shared" : "my-folders";
    } else {
      activeNav = routes[route] ? route : "home";
    }
    const hash = currentFolderId ? "#folder/" + encodeURIComponent(currentFolderId) : "#" + (routes[activeNav] || "overview");
    if (location.hash !== hash) {
      if (replace) history.replaceState(null,"",hash);
      else history.pushState(null,"",hash);
    }
    renderPage();
  }

  function toast(message, error = false) {
    const old = document.querySelector(".notice");
    if (old) old.remove();
    const node = document.createElement("div");
    node.className = "notice" + (error ? " error" : "");
    node.textContent = message;
    document.body.appendChild(node);
    setTimeout(() => node.remove(), 3500);
  }

  function run(action, successMessage, rerender = true) {
    return async () => {
      try {
        await action();
        if (rerender) renderPage();
        if (successMessage) toast(successMessage);
      } catch (error) {
        toast(error.message, true);
      }
    };
  }

  function touchSession() {
    if (!data) return;
    clearTimeout(sessionTimer);
    sessionTimer = setTimeout(() => logout(true), SESSION_LENGTH);
  }

  async function boot() {
    try {
      const result = await request("state", null, "GET");
      if (result.authenticated) {
        data = result.data;
        render();
        touchSession();
      } else {
        renderAuth();
      }
    } catch (error) {
      if (error.status === 401) {
        renderAuth();
        return;
      }
      root.innerHTML = `<main class="auth-wrap"><section class="auth-brand"><div class="brand"><span class="brandmark">S</span><span>Storage</span></div><h1>Your files.<br>Together, better.</h1><p>A calm, simple place to organize your work and share it with the people who matter.</p></section><section class="auth-panel"><div class="auth-box"><h2>Storage needs its backend</h2><p class="auth-sub">${escapeHTML(error.message)}</p><p>Start Apache and MySQL in XAMPP, import <code>schema.sql</code> in phpMyAdmin, then open this page at <code>http://localhost/folders/</code>.</p><button class="btn btn-primary" id="retry">Try again</button></div></section></main>`;
      document.getElementById("retry").addEventListener("click",boot);
    }
  }

  function renderAuth(mode = "login") {
    clearTimeout(sessionTimer);
    const signup = mode === "signup";
    const forgot = mode === "forgot";
    document.documentElement.dataset.theme = theme;
    const title = signup ? "Create your account" : (forgot ? "Reset your password" : "Welcome back");
    const subtitle = signup ? "Create your Storage account." : (forgot ? "We'll email you a six-digit, one-time reset code." : "Sign in to pick up where you left off.");
    const emailField = `<div class="field"><label for="auth-email">Email address</label><input id="auth-email" name="email" type="email" autocomplete="email" required maxlength="190" placeholder="you@example.com"></div>`;
    const usernameField = `<div class="field"><label for="auth-username">Username</label><input id="auth-username" name="username" autocomplete="username" required minlength="3" maxlength="30" pattern="[A-Za-z0-9_.\\-]{3,30}" title="3–30 characters: letters, numbers, dots, underscores, or hyphens" placeholder="Choose a unique username"><small>Your username is unique and can be used to sign in or add friends.</small></div>`;
    const loginField = `<div class="field"><label for="auth-identifier">Username or email</label><input id="auth-identifier" name="identifier" autocomplete="username" required maxlength="190" placeholder="Username or email address"></div>`;
    const passwordField = !forgot ? `<div class="field"><label for="auth-password">Password</label><input id="auth-password" name="password" type="password" autocomplete="${signup?"new-password":"current-password"}" required minlength="8" maxlength="4096" placeholder="${signup?"At least 8 characters":"Enter your password"}"></div>` : "";
    root.innerHTML = `<main class="auth-wrap"><section class="auth-brand"><div class="brand"><span class="brandmark">S</span><span>Storage</span></div><h1>Your files.<br>Together, better.</h1><p>A calm, simple place to organize your work and share it with the people who matter.</p><div class="auth-art"><div>✦ &nbsp; Keep your projects in one place</div><div>♧ &nbsp; Share folders with your friends</div><div>↗ &nbsp; See every change as it happens</div></div></section><section class="auth-panel"><div class="auth-box"><h2>${title}</h2><p class="auth-sub">${subtitle}</p><form id="auth-form">${signup?usernameField:""}${forgot||signup?emailField:loginField}${passwordField}<button class="btn btn-primary" type="submit">${signup?"Create account":(forgot?"Send reset code":"Sign in")}</button></form>${forgot?`<div class="auth-switch"><button class="text-button" id="auth-switch">Back to sign in</button></div>`:`${!signup?'<div class="auth-switch"><button class="text-button" id="forgot-password">Forgot password?</button></div>':""}<div class="auth-switch">${signup?"Already have an account?":"New to Storage?"} <button class="text-button" id="auth-switch">${signup?"Sign in":"Create an account"}</button></div>`}<div class="auth-foot">Accounts and files are stored on this server.</div></div></section></main>`;
    const authThemeButton = document.createElement("button");
    authThemeButton.id = "auth-theme-toggle";
    authThemeButton.className = "icon-btn auth-theme-toggle";
    authThemeButton.type = "button";
    authThemeButton.addEventListener("click",toggleTheme);
    root.querySelector(".auth-wrap").appendChild(authThemeButton);
    updateThemeButtons();
    document.getElementById("auth-switch").addEventListener("click", () => renderAuth(signup || forgot ? "login" : "signup"));
    document.getElementById("forgot-password")?.addEventListener("click", () => {
      sessionStorage.removeItem("storage-reset-email");
      window.location.assign("reset-password.php");
    });
    document.getElementById("auth-form").addEventListener("submit", async event => {
      event.preventDefault();
      const form = new FormData(event.currentTarget);
      const payload = {};
      if (signup || forgot) payload.email = String(form.get("email")).trim();
      if (signup) payload.username = String(form.get("username")).trim();
      if (!signup && !forgot) payload.identifier = String(form.get("identifier")).trim();
      if (!forgot) payload.password = String(form.get("password"));
      try {
        const result = await request(signup ? "signup" : (forgot ? "forgot-password" : "login"),payload);
        if (forgot) {
          sessionStorage.setItem("storage-reset-email",payload.email);
          window.location.assign("reset-password.php");
          return;
        }
        data = result.data;
        syncRouteFromLocation();
        render();
        touchSession();
        toast(signup ? "Account created. Welcome to Storage!" : "Welcome back, " + data.me.name.split(" ")[0] + ".");
      } catch (error) {
        toast(error.message,true);
      }
    });
  }

  async function logout(expired = false) {
    clearTimeout(sessionTimer);
    try {
      if (data) await request("logout",{});
    } catch (error) {
      console.error("Could not close the server session.",error);
    }
    data = null;
    try {
      await request("csrf",null,"GET");
    } catch (error) {
      console.error("Could not start a secure sign-in session.",error);
      toast("Could not start a secure sign-in session. Refresh the page.",true);
    }
    activeNav = "home";
    currentFolderId = null;
    history.replaceState(null,"","#overview");
    renderAuth();
    if (expired) toast("Your session expired due to inactivity. Please sign in again.");
  }

  function render() {
    const user = me();
    if (!user) { renderAuth(); return; }
    syncRouteFromLocation();
    const incoming = pendingRequests().length;
    document.documentElement.dataset.theme = theme;
    root.innerHTML = `<div class="app"><aside class="sidebar"><div class="brand"><span class="brandmark">S</span><span class="brand-name">Storage</span></div><div class="nav-label">WORKSPACE</div><nav class="nav"><button class="nav-btn ${activeNav==="home"?"active":""}" data-nav="home"><span class="nav-icon">⌂</span><span>Overview</span></button><button class="nav-btn ${activeNav==="my-folders"?"active":""}" data-nav="my-folders"><span class="nav-icon">▤</span><span>My folders</span><span class="nav-count">${ownedFolders().length}</span></button><button class="nav-btn ${activeNav==="shared"?"active":""}" data-nav="shared"><span class="nav-icon">♧</span><span>Shared with me</span></button><button class="nav-btn ${activeNav==="friends"?"active":""}" data-nav="friends"><span class="nav-icon">♙</span><span>Friends</span>${incoming?`<span class="nav-count">${incoming}</span>`:""}</button><button class="nav-btn ${activeNav==="activity"?"active":""}" data-nav="activity"><span class="nav-icon">◷</span><span>Activity report</span></button></nav><div class="sidebar-bottom"><div class="storage-card"><div class="storage-row"><span>Server storage</span><strong id="storage-total">0 B</strong></div><div class="storage-bar"><span id="storage-bar"></span></div><div class="storage-row"><span>Files in your folders</span><span id="storage-count">0 files</span></div></div><div class="profile-mini"><div class="avatar">${escapeHTML(initials(user.name))}</div><div><div class="profile-name">${escapeHTML(user.name)}</div><div class="profile-email">${escapeHTML(user.email)}</div></div></div></div></aside><main class="main"><header class="topbar"><div class="breadcrumb">Storage <span style="color:#c0c3ce"> / </span> <span id="crumb-current">Overview</span></div><div class="top-actions"><button class="icon-btn" id="theme-toggle" aria-label="Switch to ${theme==="dark"?"light":"dark"} mode" title="Switch to ${theme==="dark"?"light":"dark"} mode">${theme==="dark"?"☀":"☾"}</button><button class="icon-btn" id="activity-shortcut" aria-label="Activity report">◷</button><button class="icon-btn" id="logout-btn" aria-label="Sign out" title="Sign out">↪</button><div class="user-top"><div class="avatar">${escapeHTML(initials(user.name))}</div><span>${escapeHTML(user.name.split(" ")[0])}</span></div></div></header><section class="content" id="page"></section></main></div>`;
    if(user.role==="admin")document.querySelector(".nav").insertAdjacentHTML("beforeend",`<button class="nav-btn ${activeNav==="admin"?"active":""}" data-nav="admin"><span class="nav-icon">⚙</span><span>Administration</span></button>`);
    document.querySelectorAll("[data-nav]").forEach(button => button.addEventListener("click",() => navigate(button.dataset.nav)));
    document.getElementById("logout-btn").addEventListener("click",() => logout(false));
    document.getElementById("activity-shortcut").addEventListener("click",() => navigate("activity"));
    document.getElementById("theme-toggle").addEventListener("click",toggleTheme);
    renderPage();
    refreshStorage();
  }

  function folderHeading(title, description, createButton = true) {
    return `<div class="welcome"><div><div class="eyebrow">YOUR WORKSPACE</div><h1>${title}</h1><p>${description}</p></div>${createButton?'<button class="btn btn-primary" id="create-folder">＋ &nbsp;New folder</button>':""}</div>`;
  }

  function renderPage() {
    const page = document.getElementById("page");
    if (!page || !data) return;
    syncRouteFromLocation();
    document.querySelectorAll("[data-nav]").forEach(button => button.classList.toggle("active",button.dataset.nav===activeNav));
    const labels={home:"Overview","my-folders":"My folders",shared:"Shared with me",friends:"Friends",activity:"Activity report",admin:"Administration"};
    const currentFolder=currentFolderId&&data.folders.find(folder=>String(folder.id)===currentFolderId);
    document.getElementById("crumb-current").textContent=currentFolder?currentFolder.name:(labels[activeNav]||"Overview");
    if (currentFolderId) {
      if (currentFolder) renderFolder(currentFolderId);
      else navigate("my-folders",null,true);
      return;
    }
    if(activeNav==="friends")renderFriends(page);
    else if(activeNav==="activity")renderActivity(page);
    else if(activeNav==="admin")renderAdmin(page);
    else renderFoldersPage(page,activeNav);
  }

  function renderFoldersPage(page, view) {
    const owned=ownedFolders(), shared=permittedFolders();
    if(view==="home") {
      const totalFiles=owned.reduce((total,folder)=>total+folder.files.length,0);
      const people=data.friendships.length;
      page.innerHTML=`${folderHeading("Good to see you, "+escapeHTML(me().name.split(" ")[0])+" 👋","Everything you need, right where you left it.")}<div class="stat-grid"><div class="stat-card"><div class="stat-icon purple">▤</div><div><div class="stat-label">Your folders</div><div class="stat-value">${owned.length}</div></div></div><div class="stat-card"><div class="stat-icon mint">↗</div><div><div class="stat-label">Files stored</div><div class="stat-value">${totalFiles}</div></div></div><div class="stat-card"><div class="stat-icon peach">♙</div><div><div class="stat-label">Friends</div><div class="stat-value">${people}</div></div></div></div>${folderSection("Your folders",owned.slice(0,3),"mine")}<div class="section-head"><h2>Recent activity</h2><button class="text-button" id="see-activity">See all</button></div>${activityMarkup(data.activity.filter(item=>String(item.userId)===String(me().id)).slice(0,4))}`;
      document.getElementById("create-folder").addEventListener("click",createFolder);
      document.getElementById("see-activity").addEventListener("click",()=>navigate("activity"));
      wireFolderCards(page);
      return;
    }
    const isShared=view==="shared", folders=isShared?shared:owned;
    page.innerHTML=`${folderHeading(isShared?"Shared with me":"My folders",isShared?"Folders your friends have shared with you.":`${owned.length} folder${owned.length===1?"":"s"} to keep everything organized.`,!isShared)}${isShared?"":`<div class="stat-grid"><div class="stat-card"><div class="stat-icon purple">▤</div><div><div class="stat-label">Folders</div><div class="stat-value">${owned.length}</div></div></div><div class="stat-card"><div class="stat-icon mint">↗</div><div><div class="stat-label">Files</div><div class="stat-value">${owned.reduce((total,folder)=>total+folder.files.length,0)}</div></div></div><div class="stat-card"><div class="stat-icon peach">♙</div><div><div class="stat-label">Shared folders</div><div class="stat-value">${owned.filter(folder=>Object.keys(folder.permissions).length).length}</div></div></div></div>`}<div class="section-head"><h2>${isShared?"Shared folders":"All folders"}</h2><input id="folder-search" class="search" placeholder="⌕  Search folders..." value="${escapeHTML(searchQuery)}"></div><div id="folder-list">${folderCards(folders.filter(folder=>folder.name.toLowerCase().includes(searchQuery.toLowerCase())),isShared)}</div>`;
    if(!isShared)document.getElementById("create-folder").addEventListener("click",createFolder);
    document.getElementById("folder-search").addEventListener("input",event=>{searchQuery=event.target.value;document.getElementById("folder-list").innerHTML=folderCards(folders.filter(folder=>folder.name.toLowerCase().includes(searchQuery.toLowerCase())),isShared);wireFolderCards(document.getElementById("folder-list"));});
    wireFolderCards(page);
  }

  function folderSection(title,folders,mode) {
    return `<div class="section-head"><h2>${title}</h2>${mode==="mine"?'<button class="text-button" data-goto="my-folders">View all</button>':""}</div>${folderCards(folders,false)}`;
  }

  function folderCards(folders,shared) {
    if(!folders.length)return `<div class="empty-state"><div class="empty-emoji">${shared?"♧":"▤"}</div><strong>${shared?"Nothing shared with you yet":"No folders found"}</strong><span>${shared?"When a friend shares a folder, it will appear here.":"Create a folder to get started."}</span></div>`;
    return `<div class="folder-grid">${folders.map(folder=>{const people=Object.keys(folder.permissions);const owner=userById(folder.ownerId);return `<article class="folder-card" data-open-folder="${folder.id}" tabindex="0"><div class="folder-head"><span class="folder-symbol">📁</span><button class="dots" data-folder-menu="${folder.id}" aria-label="Folder options">···</button></div><h3>${escapeHTML(folder.name)}</h3><div class="folder-meta">${folder.files.length} file${folder.files.length===1?"":"s"}${shared&&owner?" · by "+escapeHTML(owner.name.split(" ")[0]):""}</div><div class="folder-bottom"><span>${shared?`<span class="badge ${folder.permissions[me().id]==="editor"?"editor":""}">${folder.permissions[me().id]}</span>`:dateText(folder.updatedAt)}</span><span class="shared-stack">${people.slice(0,3).map(id=>{const person=userById(id);return person?`<span class="shared-avatar" title="${escapeHTML(person.name)}">${escapeHTML(initials(person.name))}</span>`:"";}).join("")}</span></div></article>`;}).join("")}</div>`;
  }

  function wireFolderCards(container) {
    container.querySelectorAll("[data-open-folder]").forEach(card=>{const open=()=>navigateToFolder(card.dataset.openFolder);card.addEventListener("click",event=>{if(event.target.closest("[data-folder-menu]"))return;open();});card.addEventListener("keydown",event=>{if(event.key==="Enter")open();});});
    container.querySelectorAll("[data-folder-menu]").forEach(button=>button.addEventListener("click",event=>{event.stopPropagation();showFolderMenu(data.folders.find(folder=>folder.id===button.dataset.folderMenu));}));
    container.querySelectorAll("[data-goto]").forEach(button=>button.addEventListener("click",()=>navigate(button.dataset.goto)));
  }

  function navigateToFolder(folderId) {
    navigate(activeNav,String(folderId));
  }

  function renderFolder(folderId) {
    const folder=data.folders.find(item=>item.id===folderId);if(!folder)return;
    const owner=String(folder.ownerId)===String(me().id),permission=folder.permissions[me().id],canEdit=owner||permission==="editor";
    const page=document.getElementById("page");
    page.innerHTML=`<div class="welcome"><div><button class="text-button" id="back-folders">← ${owner?"My folders":"Shared with me"}</button><div class="eyebrow" style="margin-top:14px">FOLDER ${owner?"":"· SHARED BY "+escapeHTML(folder.ownerName||userById(folder.ownerId)?.name||"a friend")}</div><h1>📁 ${escapeHTML(folder.name)}</h1><p>${folder.files.length} file${folder.files.length===1?"":"s"} · Updated ${dateText(folder.updatedAt)}${!owner?` · <span class="badge ${permission==="editor"?"editor":""}">${permission}</span>`:""}</p></div><div style="display:flex;gap:8px">${owner?'<button class="btn btn-light" id="manage-access">♧ &nbsp;Manage access</button>':""}${canEdit?'<button class="btn btn-primary" id="upload-files">↑ &nbsp;Upload files</button>':""}</div></div><div class="section-head"><h2>Files</h2><span>Images · Videos · Documents</span></div><input type="file" class="file-input" id="file-picker" multiple accept="image/*,video/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip"><div id="file-table">${fileTable(folder,canEdit)}</div><div class="section-head"><h2>Folder activity</h2><span>Recent changes</span></div>${activityMarkup(data.activity.filter(item=>String(item.folderId)===String(folder.id)).slice(0,20))}`;
    document.getElementById("back-folders").addEventListener("click",()=>navigate(owner?"my-folders":"shared"));
    if(owner)document.getElementById("manage-access").addEventListener("click",()=>showPermissions(folder));
    if(canEdit){const picker=document.getElementById("file-picker");document.getElementById("upload-files").addEventListener("click",()=>picker.click());picker.addEventListener("change",()=>uploadFiles(folder,picker.files));}
    page.querySelectorAll("[data-delete-file]").forEach(button=>button.addEventListener("click",()=>deleteFile(folder,button.dataset.deleteFile)));
    page.querySelectorAll("[data-preview-file]").forEach(button=>button.addEventListener("click",()=>previewFile(folder,button.dataset.previewFile)));
  }

  function previewFile(folder,fileId) {
    const file=folder.files.find(item=>String(item.id)===String(fileId));
    if(!file||!["image","video"].includes(file.category))return;
    const url="download.php?id="+encodeURIComponent(file.id)+"&preview=1";
    const media=file.category==="image"
      ? `<img class="media-preview" src="${url}" alt="${escapeHTML(file.name)}">`
      : `<video class="media-preview" src="${url}" controls autoplay playsinline>Your browser does not support this video format.</video>`;
    showDialog(file.name,`<div class="preview-stage">${media}</div><div class="preview-caption">${escapeHTML(file.name)} <span>${bytesText(file.size)}</span></div>`,[{text:"Close preview",className:"btn-light"},{text:"Download",className:"btn-primary",action:()=>{const link=document.createElement("a");link.href="download.php?id="+encodeURIComponent(file.id);link.download=file.name;link.click();return false;}}],true);
  }

  function fileTable(folder,canEdit) {
    if(!folder.files.length)return `<div class="empty-state"><div class="empty-emoji">📂</div><strong>No files in this folder yet</strong><span>${canEdit?"Upload images, videos, or documents to get started.":"The folder owner hasn't added files yet."}</span></div>`;
    const rows=folder.files.map(file=>`<div class="file-row"><div class="file-name"><div class="file-type-icon">${icons[file.category]||icons.other}</div><div style="min-width:0"><div class="file-title" title="${escapeHTML(file.name)}">${escapeHTML(file.name)}</div><div class="file-sub">${bytesText(file.size)}</div></div></div><div class="file-cell">${escapeHTML(file.category[0].toUpperCase()+file.category.slice(1))}</div><div class="file-cell file-hide-mobile">${dateText(file.addedAt)}</div><div class="file-cell file-hide-mobile">${escapeHTML(userById(file.addedBy)?.name.split(" ")[0]||"Unknown")}</div><div class="file-actions">${file.category==="image"||file.category==="video"?`<button class="file-action" data-preview-file="${file.id}" title="Preview ${escapeHTML(file.name)}" aria-label="Preview ${escapeHTML(file.name)}">◉</button>`:""}<a class="file-action" href="download.php?id=${encodeURIComponent(file.id)}" title="Download file" aria-label="Download ${escapeHTML(file.name)}">↓</a>${canEdit?`<button class="file-action" data-delete-file="${file.id}" title="Delete file" aria-label="Delete ${escapeHTML(file.name)}">×</button>`:""}</div></div>`).join("");
    return `<div class="file-list"><div class="file-row file-header"><div>Name</div><div>Type</div><div class="file-hide-mobile">Date added</div><div class="file-hide-mobile">Added by</div><div></div></div>${rows}</div>`;
  }

  async function uploadFiles(folder,files) {
    if(!files?.length)return;
    const form=new FormData();form.append("folderId",folder.id);
    Array.from(files).forEach(file=>form.append("files[]",file));
    try {await request("upload",form);renderFolder(folder.id);refreshStorage();toast("Files uploaded.");}
    catch(error){toast(error.message,true);}
  }

  function deleteFile(folder,fileId) {
    const file=folder.files.find(item=>item.id===fileId);if(!file||!confirm(`Delete "${file.name}"? This cannot be undone.`))return;
    run(async()=>{await request("delete-file",{fileId,fileName:file.name});renderFolder(folder.id);refreshStorage();},"File deleted.",false)();
  }

  function showFolderMenu(folder) {
    if(!folder)return;
    if(String(folder.ownerId)!==String(me().id)) {
      showDialog("Leave shared folder",`<p class="auth-sub">Remove your access to “${escapeHTML(folder.name)}”? You can ask the owner to share it again later.</p>`,[{text:"Cancel",className:"btn-light"},{text:"Leave folder",className:"btn-danger",action:async()=>{await request("leave-folder",{folderId:folder.id});navigate("shared");}}]);
      return;
    }
    showDialog("Folder options",`<p class="auth-sub">Manage “${escapeHTML(folder.name)}”.</p>`,[{text:"Cancel",className:"btn-light"},{text:"Manage access",className:"btn-light",action:()=>showPermissions(folder)},{text:"Delete folder",className:"btn-danger",action:()=>deleteFolder(folder)}]);
  }

  function createFolder() {
    showDialog("Create a folder",'<div class="field"><label for="new-folder-name">Folder name</label><input id="new-folder-name" maxlength="80" placeholder="e.g. Design project"></div>',[{text:"Cancel",className:"btn-light"},{text:"Create folder",className:"btn-primary",action:async()=>{const name=document.getElementById("new-folder-name").value.trim();if(!name){toast("Enter a folder name to continue.",true);return false;}await request("create-folder",{name});renderPage();toast("Folder created.");}}]);
  }

  function deleteFolder(folder) {
    showDialog("Delete folder?",`<p class="auth-sub">Delete “${escapeHTML(folder.name)}” and all of its files? This cannot be undone.</p>`,[{text:"Cancel",className:"btn-light"},{text:"Delete folder",className:"btn-danger",action:async()=>{await request("delete-folder",{folderId:folder.id});navigate("my-folders");refreshStorage();toast("Folder deleted.");}}]);
  }

  function showPermissions(folder) {
    const friends=data.friendships.map(link=>userById(link.userIds.find(id=>String(id)!==String(me().id)))).filter(Boolean);
    const permissions=folder.permissions;
    showDialog("Manage folder access",`<p class="auth-sub">Choose which friends can access “${escapeHTML(folder.name)}”. Editors can add and delete files; viewers can only view them.</p>${friends.length?`<div class="modal-list">${friends.map(friend=>`<div class="permission-row"><div class="avatar">${escapeHTML(initials(friend.name))}</div><div class="friend-info"><strong>${escapeHTML(friend.name)}</strong><span>${escapeHTML(friend.email)}</span></div><select data-permission-user="${friend.id}" aria-label="Permission for ${escapeHTML(friend.name)}"><option value="">No access</option><option value="viewer" ${permissions[friend.id]==="viewer"?"selected":""}>Viewer</option><option value="editor" ${permissions[friend.id]==="editor"?"selected":""}>Editor</option></select></div>`).join("")}</div>`:'<div class="empty-state"><strong>Add friends to share folders</strong><span>You need to connect with someone before sharing.</span></div>'}`,[{text:"Done",className:"btn-light"},{text:"Save permissions",className:"btn-primary",action:async()=>{const next={};document.querySelectorAll("[data-permission-user]").forEach(select=>{if(select.value)next[select.dataset.permissionUser]=select.value;});await request("permissions",{folderId:folder.id,permissions:next});renderPage();toast("Folder permissions updated.");}}]);
  }

  function renderFriends(page) {
    const friends=data.friendships.map(link=>userById(link.userIds.find(id=>String(id)!==String(me().id)))).filter(Boolean);
    const incoming=pendingRequests(),sent=data.requests.filter(request=>String(request.fromId)===String(me().id));
    page.innerHTML=`${folderHeading("Friends","Connect with people to share folders and files.",false)}<div class="section-head"><h2>Your friends <span class="badge">${friends.length}</span></h2><button class="btn btn-primary btn-sm" id="add-friend">＋ &nbsp;Add friend</button></div><div class="friend-grid">${friends.length?friends.map(friend=>`<div class="friend-card"><div class="avatar">${escapeHTML(initials(friend.name))}</div><div class="friend-info"><strong>${escapeHTML(friend.name)}</strong><span>${escapeHTML(friend.email)}</span></div><button class="dots" data-remove-friend="${friend.id}" title="Remove friend">···</button></div>`).join(""):'<div class="empty-state" style="grid-column:1/-1"><div class="empty-emoji">♙</div><strong>No friends yet</strong><span>Send a friend request by username or email to start sharing folders.</span></div>'}</div><div class="section-head"><h2>Friend requests <span class="badge">${incoming.length}</span></h2></div><div class="tabs"><button class="tab active" data-request-tab="received">Received${incoming.length?` (${incoming.length})`:""}</button><button class="tab" data-request-tab="sent">Sent${sent.length?` (${sent.length})`:""}</button></div><div id="request-list"></div>`;
    document.getElementById("add-friend").addEventListener("click",addFriend);
    page.querySelectorAll("[data-remove-friend]").forEach(button=>button.addEventListener("click",()=>removeFriend(button.dataset.removeFriend)));
    function setTab(tab) {
      page.querySelectorAll("[data-request-tab]").forEach(button=>button.classList.toggle("active",button.dataset.requestTab===tab));
      const requests=tab==="received"?incoming:sent;
      document.getElementById("request-list").innerHTML=requests.length?`<div class="friend-grid">${requests.map(item=>{const person=userById(tab==="received"?item.fromId:item.toId);if(!person)return "";return `<div class="friend-card"><div class="avatar">${escapeHTML(initials(person.name))}</div><div class="friend-info"><strong>${escapeHTML(person.name)}</strong><span>${escapeHTML(person.email)} · ${tab==="received"?"sent "+relativeTime(item.createdAt):"waiting for a response"}</span></div>${tab==="received"?`<button class="btn btn-primary btn-sm" data-accept="${item.id}">Accept</button><button class="btn btn-light btn-sm" data-reject="${item.id}">Reject</button>`:`<button class="btn btn-light btn-sm" data-cancel="${item.id}">Cancel</button>`}</div>`;}).join("")}</div>`:`<div class="empty-state"><strong>No ${tab} requests</strong><span>${tab==="received"?"New friend requests will show up here.":"Requests you send will show up here."}</span></div>`;
      document.querySelectorAll("[data-accept]").forEach(button=>button.addEventListener("click",()=>respondRequest(button.dataset.accept,true)));
      document.querySelectorAll("[data-reject]").forEach(button=>button.addEventListener("click",()=>respondRequest(button.dataset.reject,false)));
      document.querySelectorAll("[data-cancel]").forEach(button=>button.addEventListener("click",()=>run(async()=>{await request("cancel-request",{requestId:button.dataset.cancel});}, "Request cancelled.")()));
    }
    page.querySelectorAll("[data-request-tab]").forEach(button=>button.addEventListener("click",()=>setTab(button.dataset.requestTab)));
    setTab("received");
  }

  function addFriend() {
    showDialog("Add a friend",'<div class="field"><label for="friend-identifier">Username or email address</label><input id="friend-identifier" maxlength="190" placeholder="username or friend@example.com"></div><small>Find an account registered on this Storage server.</small>',[{text:"Cancel",className:"btn-light"},{text:"Send request",className:"btn-primary",action:async()=>{const identifier=document.getElementById("friend-identifier").value.trim();if(!identifier){toast("Enter a username or email address.",true);return false;}await request("send-request",{identifier});renderPage();toast("Friend request sent.");}}]);
  }

  function respondRequest(requestId,accept) {
    run(async()=>{await request("respond-request",{requestId,accept});},accept?"Friend request accepted.":"Friend request declined.")();
  }

  function removeFriend(friendId) {
    const friend=userById(friendId);if(!friend)return;
    showDialog("Remove friend?",`<p class="auth-sub">Remove ${escapeHTML(friend.name)} from your friends? Their access to your shared folders will be removed.</p>`,[{text:"Cancel",className:"btn-light"},{text:"Remove friend",className:"btn-danger",action:async()=>{await request("remove-friend",{friendId});renderPage();toast("Friend removed.");}}]);
  }

  async function renderAdmin(page) {
    page.innerHTML=`${folderHeading("Administration","Manage Storage accounts and monitor server storage.",false)}<div class="admin-loading empty-state">Loading administrator data…</div>`;
    try {
      const result=await request("admin-data",null,"GET");
      if(activeNav!=="admin"||!page.isConnected)return;
      const admin=result.admin;
      page.innerHTML=`${folderHeading("Administration","Manage Storage accounts and monitor server storage.",false)}<div class="stat-grid"><div class="stat-card"><div class="stat-icon purple">♙</div><div><div class="stat-label">Accounts</div><div class="stat-value">${admin.summary.total_users}</div></div></div><div class="stat-card"><div class="stat-icon mint">▤</div><div><div class="stat-label">Folders</div><div class="stat-value">${admin.summary.total_folders}</div></div></div><div class="stat-card"><div class="stat-icon peach">↗</div><div><div class="stat-label">Files · ${bytesText(Number(admin.summary.total_bytes))}</div><div class="stat-value">${admin.summary.total_files}</div></div></div></div><div class="section-head"><h2>Accounts</h2><span>${admin.users.length} registered</span></div><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Username</th><th>Email</th><th>Role</th><th>Folders</th><th>Files</th><th>Storage</th><th>Joined</th><th></th></tr></thead><tbody>${admin.users.map(account=>`<tr><td><strong>${escapeHTML(account.username)}</strong>${String(account.id)===String(me().id)?' <span class="badge">You</span>':""}</td><td>${escapeHTML(account.email)}</td><td><span class="badge ${account.role==="admin"?"admin-badge":""}">${escapeHTML(account.role)}</span></td><td>${account.folder_count}</td><td>${account.file_count}</td><td>${bytesText(Number(account.storage_bytes))}</td><td>${dateText(Date.parse(account.created_at+"Z"))}</td><td>${String(account.id)!==String(me().id)?`<button class="btn btn-danger btn-sm" data-admin-delete="${account.id}" data-username="${escapeHTML(account.username)}" ${account.role==="admin"&&Number(admin.users.filter(user=>user.role==="admin").length)<=1?"disabled title=\"Promote another admin before removing this account\"":""}>Remove</button>`:""}</td></tr>`).join("")}</tbody></table></div><p class="admin-note">Removing an account permanently deletes its folders, permissions, and uploaded files. You cannot remove your own account.</p>`;
      page.querySelectorAll("[data-admin-delete]").forEach(button=>button.addEventListener("click",()=>deleteAccount(button.dataset.adminDelete,button.dataset.username)));
    } catch(error) {
      if(activeNav==="admin"&&page.isConnected)page.innerHTML=`${folderHeading("Administration","Manage Storage accounts and monitor server storage.",false)}<div class="empty-state"><strong>Could not load administrator data</strong><span>${escapeHTML(error.message)}</span></div>`;
    }
  }

  function deleteAccount(userId,username) {
    showDialog("Remove account?",`<p class="auth-sub">Permanently delete <strong>${escapeHTML(username)}</strong>, their folders, and uploaded files? This cannot be undone.</p>`,[{text:"Cancel",className:"btn-light"},{text:"Remove account",className:"btn-danger",action:async()=>{await request("admin-delete-user",{userId});await renderPage();toast("Account and its files were removed.");}}]);
  }

  function activityMarkup(items) {
    if(!items.length)return '<div class="empty-state"><div class="empty-emoji">◷</div><strong>No activity yet</strong><span>Actions in your folders will appear here.</span></div>';
    const actionIcons={created:"＋",uploaded:"↑",deleted:"×","deleted folder":"×",opened:"◉",left:"↙","permission changed":"♧","permission removed":"−"};
    return `<div class="activity-list">${items.map(item=>{const actor=userById(item.userId);return `<div class="activity-row"><div class="activity-dot">${actionIcons[item.action]||"•"}</div><div class="activity-copy"><strong>${escapeHTML(actor?.name||"Someone")}</strong> ${escapeHTML(item.action)} ${item.folderName?`in <strong>${escapeHTML(item.folderName)}</strong>`:""}${item.detail?` · ${escapeHTML(item.detail)}`:""}<div class="activity-time">${relativeTime(item.time)}</div></div></div>`;}).join("")}</div>`;
  }

  function renderActivity(page) {
    const visible=allVisibleFolders().map(folder=>String(folder.id));
    const items=data.activity.filter(item=>String(item.userId)===String(me().id)||(item.folderId&&visible.includes(String(item.folderId))));
    page.innerHTML=`${folderHeading("Activity report","A history of changes across folders you own and share.",false)}<div class="section-head"><h2>Recent actions</h2><span>${items.length} record${items.length===1?"":"s"} · last 500 actions</span></div>${activityMarkup(items)}`;
  }

  function showDialog(title,body,actions,wide=false) {
    const overlay=document.createElement("div");overlay.className="overlay";
    overlay.innerHTML=`<section class="modal ${wide?"wide":""}" role="dialog" aria-modal="true" aria-label="${escapeHTML(title)}"><div class="modal-head"><div><h2>${title}</h2></div><button class="close-btn" aria-label="Close">×</button></div><div class="modal-body">${body}</div><div class="modal-footer">${actions.map((action,index)=>`<button class="btn ${action.className} ${index===0?"btn-sm":""}" data-action="${index}">${action.text}</button>`).join("")}</div></section>`;
    document.body.appendChild(overlay);
    const close=()=>overlay.remove();
    overlay.querySelector(".close-btn").addEventListener("click",close);
    overlay.addEventListener("click",event=>{if(event.target===overlay)close();});
    overlay.querySelectorAll("[data-action]").forEach(button=>button.addEventListener("click",async()=>{try{const result=await actions[Number(button.dataset.action)].action?.();if(result!==false)close();}catch(error){toast(error.message,true);}}));
    overlay.addEventListener("keydown",event=>{if(event.key==="Escape")close();});
    const first=overlay.querySelector("input,select,button");if(first)first.focus();
  }

  function refreshStorage() {
    const files=ownedFolders().flatMap(folder=>folder.files);
    const size=files.reduce((sum,file)=>sum+file.size,0);
    const total=document.getElementById("storage-total");if(total)total.textContent=bytesText(size);
    const count=document.getElementById("storage-count");if(count)count.textContent=files.length+" file"+(files.length===1?"":"s");
    const bar=document.getElementById("storage-bar");if(bar)bar.style.width=Math.min(size/(1024*1024*1024)*100,100)+"%";
  }

  document.addEventListener("pointerdown",touchSession,{passive:true});
  document.addEventListener("keydown",touchSession);
  document.addEventListener("visibilitychange",()=>{if(!document.hidden&&data)touchSession();});
  window.addEventListener("popstate",()=>{if(data){syncRouteFromLocation();renderPage();}});
  boot();
})();
