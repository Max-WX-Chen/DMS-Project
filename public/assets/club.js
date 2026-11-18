import { admin, api, button, escape, feedback, head, href, link, query, session, state, view } from './ui.js';
import { authPage, checkout, home, managePlans, memberPage, membersPage, membershipPage, orderPage, plansPage, profilePage } from './membership.js';
import { bookingsPage, dashboardPage, editSession, reportsPage, resourcesPage, rosterPage, schedulePage, sessionPage } from './classes.js';

const screens = {
  home, login:authPage, register:authPage, plans:plansPage, checkout, order:orderPage,
  membership:membershipPage, profile:profilePage, members:membersPage, member:memberPage,
  'admin-plans':managePlans, schedule:schedulePage, session:sessionPage, bookings:bookingsPage,
  dashboard:dashboardPage, reports:reportsPage, resources:resourcesPage, roster:rosterPage,
  'edit-session':editSession, 'admin-schedule':schedulePage
};
const adminTabs = [
  ['dashboard','Overview'],['members','Members'],['admin-plans','Plans & orders'],
  ['resources','Resources'],['admin-schedule','Schedule & check-in'],['reports','Reports']
];

function navLinks(items) {
  return items.map(([name,title])=>'<a href="' + escape(href(name)) + '"' +
    (view === name ? ' class="active" aria-current="page"' : '') + '>' + escape(title) + '</a>').join('');
}

function navigation() {
  const items = [['home','Discover'],['schedule','Classes'],['plans','Membership']];
  if (state.user && !admin()) items.push(['bookings','My bookings'],['membership','My membership']);
  if (admin()) items.push(['dashboard','Staff dashboard']);
  document.querySelector('#navigation').innerHTML = navLinks(items);
  document.querySelector('#account-tools').innerHTML = state.user ?
    (!admin() ? link('Profile','profile') : '') + button('auth.logout','Sign out',{}) :
    link('Sign in','login') + link('Join the club ↗','register',{},'btn lime');
  const workspace = [...adminTabs.map(([name])=>name),'member','roster','edit-session','order'];
  document.querySelector('#admin-navigation').innerHTML = admin() && workspace.includes(view) ?
    '<nav class="club-tabs" aria-label="Club management">' + navLinks(adminTabs) + '</nav>' : '';
}

async function render() {
  const container = document.querySelector('#page');
  container.setAttribute('aria-busy','true');
  try {
    await session();
    navigation();
    container.innerHTML = Object.hasOwn(screens,view) ? await screens[view]() : head('Page unavailable') + link('Return home','home');
  } catch (error) {
    container.innerHTML = head('Unable to load this page') + link('Return home','home');
    feedback(error.message,true);
  } finally { container.setAttribute('aria-busy','false'); }
}

function returnAfterLogin() {
  const next = query.get('next');
  if (!next || !Object.hasOwn(screens,next) || ['login','register'].includes(next)) return href(admin() ? 'dashboard' : 'membership');
  const params = Object.fromEntries(query);
  delete params.next;
  delete params.view;
  return href(next,params);
}

function destination(action, data) {
  switch (action) {
    case 'auth.login':
    case 'auth.register': return returnAfterLogin();
    case 'auth.logout': return href('home');
    case 'orders.create': return href('order',{order_id:data.order.order_id});
    case 'bookings.create': return href('bookings');
    case 'sessions.save': return href('roster',{session_id:data.session_id});
    case 'resources.save': return href('resources',{kind:query.get('kind') || 'coach',offset:query.get('offset')});
    default: return location.pathname + location.search;
  }
}

const messages = {
  'auth.login':'Signed in.', 'auth.register':'Account created.', 'auth.logout':'Signed out.',
  'profile.update':'Contact details saved.', 'profile.password':'Password updated.',
  'orders.create':'Order created.', 'orders.pay':'Payment confirmed.', 'orders.confirm':'Receipt confirmed.',
  'orders.cancel':'Order cancelled.', 'plans.create':'Plan version created.', 'plans.status':'Plan status updated.',
  'members.status':'Account status updated.', 'resources.save':'Resource saved.', 'sessions.save':'Session saved.',
  'sessions.complete':'Session completed.', 'sessions.cancel':'Session cancelled.',
  'bookings.create':'Booking confirmed.', 'bookings.cancel':'Booking cancelled.', 'attendance.checkin':'Attendance recorded.'
};

document.addEventListener('submit', async event => {
  const target = event.target;
  if (!(target instanceof HTMLFormElement) || !target.dataset.action) return;
  event.preventDefault();
  const action = target.dataset.action;
  if (target.dataset.busy) return;
  const values = Object.fromEntries(new FormData(target));
  target.dataset.busy = '1';
  const submit = target.querySelector('button[type=submit]');
  submit.disabled = true;
  feedback('');
  try {
    const data = await api(action,values,true);
    const writeTiming = state.times.at(-1);
    if (action.startsWith('auth.') || action === 'profile.password') await session();
    sessionStorage.setItem('form-feedback',JSON.stringify({message:messages[action],timing:writeTiming}));
    location.assign(destination(action,data));
  } catch (error) {
    feedback(error.message,true);
    delete target.dataset.busy;
    submit.disabled = false;
  }
});

const stored = sessionStorage.getItem('form-feedback');
if (stored) {
  sessionStorage.removeItem('form-feedback');
  try {
    const flash = JSON.parse(stored);
    feedback(flash.message || '');
    if (flash.timing) state.times.push(flash.timing);
  } catch { /* An old flash message can be ignored. */ }
}
render();
