// Shared DOM and API helpers for the dynamic version of the accepted static pages.
export const query = new URLSearchParams(location.search);
export const view = query.get('view') || 'home';
export const state = { user: null, csrf: '', times: [] };

export function escape(value) {
  return String(value ?? '').replace(/[&<>"']/g, ch => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  })[ch]);
}

export function href(name, params = {}) {
  const target = new URLSearchParams({ view: name });
  for (const [key, value] of Object.entries(params)) {
    if (value !== null && value !== undefined && value !== '') target.set(key, value);
  }
  return '?' + target.toString();
}

export function link(text, name, params = {}, css = 'text-link') {
  return `<a class="${css}" href="${escape(href(name, params))}">${escape(text)}</a>`;
}

export function feedback(message, isError = false) {
  const node = document.querySelector('#feedback');
  node.textContent = message;
  node.classList.toggle('error', isError);
  if (message && isError) node.scrollIntoView({ block: 'nearest' });
}

export async function api(action, values = {}, write = false) {
  const params = new URLSearchParams({ action });
  const options = { method: write ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store' };
  if (write) {
    options.headers = { 'Content-Type': 'application/json', 'X-CSRF-Token': state.csrf };
    options.body = JSON.stringify(values);
  } else {
    Object.entries(values).forEach(([key, value]) => {
      if (value !== undefined && value !== null && value !== '') params.set(key, value);
    });
  }
  const response = await fetch('api.php?' + params, options);
  let result;
  try { result = await response.json(); }
  catch { throw new Error('The server returned an unreadable response. Check the PHP and database configuration.'); }
  if (Number.isFinite(Number(result.db_ms)) && result.db_ms != null) {
    const duration = Number(result.db_ms).toFixed(2);
    state.times.push(`${action} ${duration} ms`);
    document.querySelector('#timing').textContent = `Database · ${state.times.join(' / ')}`;
  }
  if (!result.ok) throw new Error(result.error?.message || 'Request failed.');
  return result.data;
}

export async function session() {
  const data = await api('auth.session');
  state.user = data.user;
  state.csrf = data.csrf_token;
}

export function head(title, subtitle = '') {
  document.title = title + ' — FORM Club';
  return `<div class="page-head"><h1>${escape(title)}</h1>${subtitle ? '<p>' + escape(subtitle) + '</p>' : ''}</div>`;
}

export function section(title, body) {
  return `<section class="club-block"><h2>${escape(title)}</h2>${body}</section>`;
}

export function table(items, columns, actions) {
  if (!items?.length) return '<p class="club-empty">No matching records.</p>';
  const headings = columns.map(([, label]) => '<th scope="col">' + escape(label) + '</th>').join('');
  const rows = items.map(item => {
    const cells = columns.map(([key]) => '<td>' + escape(item[key] ?? '—') + '</td>').join('');
    return '<tr>' + cells + (actions ? '<td><div class="row-actions">' + actions(item) + '</div></td>' : '') + '</tr>';
  });
  return '<div class="table-wrap"><table><thead><tr>' + headings + (actions ? '<th scope="col">Actions</th>' : '') +
    '</tr></thead><tbody>' + rows.join('') + '</tbody></table></div>';
}

export function facts(values) {
  return '<dl class="club-facts">' + values.map(([label, value]) =>
    '<dt>' + escape(label) + '</dt><dd>' + escape(value ?? '—') + '</dd>').join('') + '</dl>';
}

export function hidden(name, value) {
  return `<input type="hidden" name="${escape(name)}" value="${escape(value)}">`;
}

export function field(name, label, type = 'text', value = '', options = {}) {
  const attrs = Object.entries(options).filter(([,v]) => v !== false).map(([k,v]) =>
    v === true ? escape(k) : `${escape(k)}="${escape(v)}"`).join(' ');
  return `<div class="field"><label>${escape(label)}<input name="${escape(name)}" type="${escape(type)}" value="${escape(value)}" ${attrs}></label></div>`;
}

export function select(name, label, choices, value = '', required = true) {
  const html = choices.map(([id, text]) => `<option value="${escape(id)}"${String(id) === String(value) ? ' selected' : ''}>${escape(text)}</option>`).join('');
  return `<div class="field"><label>${escape(label)}<select name="${escape(name)}" ${required ? 'required' : ''}>${html}</select></label></div>`;
}

export function form(action, body, submit, css = 'form-stack club-form card') {
  return `<form method="post" class="${css}" data-action="${escape(action)}">${body}<button type="submit" class="btn">${escape(submit)}</button></form>`;
}

export function button(action, label, params) {
  return form(action, Object.entries(params).map(([name,val]) => hidden(name,val)).join(''), label, 'row-actions');
}

export function filter(body, submit = 'Apply filters') {
  return `<form class="toolbar" method="get">${hidden('view',view)}${body}<button class="btn" type="submit">${escape(submit)}</button>${link('Reset',view,{},'btn outline')}</form>`;
}

export function paging(rows, key = 'offset', limit = 20) {
  const offset = Math.max(0, Number(query.get(key)) || 0);
  const params = Object.fromEntries(query);
  const previous = offset > 0 ? link('← Previous',view,{...params,[key]:Math.max(0,offset-limit)}) : '';
  const next = rows.length >= limit ? link('Next →',view,{...params,[key]:offset+limit}) : '';
  return '<nav class="pager" aria-label="Results pages">' + previous + next + '</nav>';
}

export function listParams(names = [], key = 'offset', limit = 20) {
  const values = Object.fromEntries(names.map(name => [name,query.get(name)]));
  return {...values, limit, offset: Math.max(0, Number(query.get(key)) || 0)};
}

export function rows(value) { return Array.isArray(value) ? value : value?.items || []; }
export const admin = () => state.user?.role === 'ADMIN';
export function guarded(required) {
  if (!state.user) {
    const params = Object.fromEntries(query);
    delete params.view;
    return head('Sign in to continue') + link('Sign in','login',{...params,next:view},'btn lime');
  }
  if (required === 'ADMIN' && !admin()) return head('Administrator access required') + link('Return to classes','schedule');
  if (required === 'MEMBER' && admin()) return head('Member access required') + link('Open staff dashboard','dashboard');
  return '';
}
