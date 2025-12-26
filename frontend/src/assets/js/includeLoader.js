// includeLoader.js — robust loader for includes with script execution
export async function loadContent(section, cb) {
  const url = `/RemoteTeamPro/frontend/src/pages/includes/${section}.html`;
  const res = await fetch(url);
  const html = await res.text();
  const parser = new DOMParser();
  const doc = parser.parseFromString(html, 'text/html');
  const fragment = document.createDocumentFragment();
  Array.from(doc.body.childNodes).forEach(node => fragment.appendChild(node));
  const main = document.getElementById('mainContent');
  if (main) main.innerHTML = '';
  if (main) main.appendChild(fragment);
  // Execute scripts (inline and module)
  doc.querySelectorAll('script').forEach(oldScript => {
    const script = document.createElement('script');
    if (oldScript.src) script.src = oldScript.src;
    if (oldScript.type) script.type = oldScript.type;
    if (oldScript.textContent) script.textContent = oldScript.textContent;
    script.async = oldScript.async;
    script.defer = oldScript.defer;
    main.appendChild(script);
  });
  if (cb) cb();
}
