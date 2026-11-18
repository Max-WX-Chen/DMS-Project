import { admin, api, button, escape, facts, field, filter, form, guarded, head, hidden, link, listParams, paging, query, rows, section, select, state, table, view } from './ui.js';

const periods = (list, showPlan = true) => table(rows(list), [
  ['membership_id','Period'],['order_id','Order'],
  ...(showPlan ? [['plan_name','Plan'],['status','Period status']] : []),
  ['starts_at','Starts'],['ends_at','Ends']
]);
const orderColumns = [['order_id','Order'],['plan_name','Plan'],['price','CNY'],['method','Method'],['status','Status'],['created_at','Created']];
const orderList = list => table(list, orderColumns, row => link('Open order','order',{order_id:row.order_id}));
const planName = row => row.name + ' · v' + row.version;

export function authPage() {
  const registration = view === 'register';
  const title = registration ? 'Join FORM Club.' : 'Welcome back.';
  const returnParams = Object.fromEntries(query);
  delete returnParams.view;
  const formFields = (registration ? field('full_name','Full name','text','',{required:true,maxlength:100,autocomplete:'name'}) : '') +
    field('email','Email address','email','',{required:true,maxlength:190,autocomplete:'email'}) +
    (registration ? field('phone','Phone (optional)','tel','',{maxlength:30,autocomplete:'tel'}) : '') +
    field('password','Password','password','',{required:true,minlength:registration ? 8 : 1,autocomplete:registration ? 'new-password' : 'current-password'});
  return head(title) + '<div class="auth-layout"><aside class="auth-aside"><h2>Move with intention.</h2><p>Find your next class and manage your membership.</p></aside><div class="auth-main"><div class="auth-box">' +
    form(registration ? 'auth.register' : 'auth.login',formFields,registration ? 'Create account' : 'Sign in','form-stack club-form') +
    '<p class="form-foot">' + link(registration ? 'Already a member? Sign in' : 'Create an account',registration ? 'login' : 'register',returnParams) + '</p></div></div></div>';
}

function planCards(items, offset = 0) {
  if (!items.length) return '<p class="club-empty">No published plans.</p>';
  return '<div class="grid-3">' + items.map(plan => '<article class="card"><h3>' + escape(plan.name) + '</h3><p class="club-amount">¥' + escape(plan.price) + '</p><p>' +
    escape(plan.duration_days) + ' days · Group class access</p><div class="actions">' +
    (admin() ? link('Manage plans','admin-plans') : link('Choose this plan','checkout',{plan_id:plan.plan_id,offset},'btn lime')) +
    '</div></article>').join('') + '</div>';
}

export async function home() {
  const [plans, sessions] = await Promise.all([api('plans.list',{status:'ACTIVE',limit:3}),api('sessions.list',{limit:3,status:'SCHEDULED'})]);
  document.title = 'Move with intention — FORM Club';
  return '<section class="hero"><div><h1>Find your<br>form.<br><em>Feel your best.</em></h1><p class="intro">Good movement. Great company. Discover classes that meet you where you are, and take you a little further.</p><div class="actions">' +
    link('Find your next class ↗','schedule',{},'btn lime') + link('Explore membership','plans',{},'btn outline') +
    '</div></div><div class="hero-art"><img src="assets/club-studio.svg" alt="An athlete stretching in the FORM Club studio" width="600" height="650"></div></section>' +
    section('Upcoming classes',table(rows(sessions),[['course_name','Class'],['starts_at','Starts'],['coach_name','Coach']],row=>link('View class','session',{session_id:row.session_id}))) +
    section('Membership plans',planCards(rows(plans).filter(row => row.status === 'ACTIVE')));
}

export async function plansPage() {
  const list = rows(await api('plans.list',{...listParams(),status:'ACTIVE'})).filter(row => row.status === 'ACTIVE');
  return head('Find your membership.','Each plan includes group classes for a fixed number of days.') +
    planCards(list,query.get('offset') || 0) + paging(list);
}

export async function checkout() {
  const denial = guarded('MEMBER');
  if (denial) return denial;
  const list = rows(await api('plans.list',{...listParams(),status:'ACTIVE'}));
  const plan = list.find(row => String(row.plan_id) === query.get('plan_id') && row.status === 'ACTIVE');
  if (!plan) return head('Plan unavailable') + link('View available plans','plans');
  const requestKey = Array.from(crypto.getRandomValues(new Uint8Array(16)), b => b.toString(16).padStart(2,'0')).join('');
  return head('Confirm your plan.') + '<div class="card club-form">' + facts([['Plan',planName(plan)],['Price','¥' + plan.price],['Validity',plan.duration_days + ' days']]) +
    form('orders.create',hidden('plan_id',plan.plan_id) + hidden('request_key',requestKey) +
      select('method','Payment method',[['SIMULATED','Simulated payment'],['OFFLINE','Pay at the club']]),'Create order','form-stack spaced') + '</div>';
}

export async function orderPage() {
  const denial = guarded();
  if (denial) return denial;
  const {order,payments,membership} = await api('orders.detail',{order_id:query.get('order_id')});
  const id = {order_id:order.order_id};
  let actions = '';
  if (order.status === 'PENDING') {
    if (order.method === 'OFFLINE') {
      actions += admin() ? button('orders.confirm','Confirm cash received',id) : '<p>Pay at the front desk to activate your membership.</p>';
    } else if (!admin()) actions += button('orders.pay','Confirm simulated payment',id);
    if (!admin()) actions += button('orders.cancel','Cancel order',id);
  }
  return head('Order #' + order.order_id) + '<div class="card">' +
    facts([['Plan',order.plan_name],['Price','¥' + order.price],['Method',order.method],['Status',order.status],['Created',order.created_at]]) +
    '<div class="row-actions">' + actions + '</div></div>' +
    section('Payment history', table(rows(payments),[['payment_id','Payment'],['status','Status'],['attempted_at','Attempted'],['paid_at','Paid']])) +
    (membership ? section('Membership period',periods([membership],false)) : '') + link('Back to orders',admin() ? 'admin-plans' : 'membership');
}

export async function membershipPage() {
  const denial = guarded('MEMBER');
  if (denial) return denial;
  const [membership,orders] = await Promise.all([api('memberships.list',listParams([],'periodOffset')),api('orders.list',listParams([],'orderOffset'))]);
  return head('My membership.') + link('Choose or renew a plan','plans',{},'btn lime') +
    section('Membership periods',periods(membership) + paging(rows(membership),'periodOffset')) +
    section('My orders',orderList(rows(orders)) + paging(rows(orders),'orderOffset'));
}

export async function profilePage() {
  const denial = guarded('MEMBER');
  if (denial) return denial;
  const attendance = rows(await api('attendance.list',listParams()));
  const user = state.user;
  return head('My profile.',user.email) + '<div class="grid-2">' +
    section('Contact information',form('profile.update',field('full_name','Full name','text',user.full_name,{required:true,maxlength:100}) +
      field('phone','Phone (optional)','tel',user.phone,{maxlength:30}),'Save contact details')) +
    section('Change password',form('profile.password', field('current_password','Current password','password','',{required:true,autocomplete:'current-password'}) +
      field('password','New password','password','',{required:true,minlength:8,autocomplete:'new-password'}),'Update password')) + '</div>' +
    section('My attendance',table(attendance,[['session_id','Session'],['course_name','Class'],['starts_at','Starts'],['status','Attendance'],['checked_at','Checked in']]) + paging(attendance));
}

export async function membersPage() {
  const denial = guarded('ADMIN');
  if (denial) return denial;
  const list = rows(await api('members.list',listParams(['q'])));
  return head('Club members.') + filter(field('q','Name, member number or email','search',query.get('q')),'Search') +
    table(list,[['user_id','Member no.'],['full_name','Name'],['email','Email address'],['status','Status']],row=>link('View member','member',{user_id:row.user_id})) + paging(list);
}

export async function memberPage() {
  const denial = guarded('ADMIN');
  if (denial) return denial;
  const {user,periods:history} = await api('members.detail',{...listParams(),user_id:query.get('user_id')});
  return head(user.full_name) + '<div class="card">' + facts([['Member no.',user.user_id],['Email',user.email],['Phone',user.phone],['Status',user.status]]) +
    button('members.status',user.status === 'ACTIVE' ? 'Disable account' : 'Enable account',{user_id:user.user_id,status:user.status === 'ACTIVE' ? 'DISABLED' : 'ACTIVE'}) + '</div>' +
    section('Paid membership periods',periods(history) + paging(rows(history))) + link('All members','members');
}

export async function managePlans() {
  const denial = guarded('ADMIN');
  if (denial) return denial;
  const [plans,orders] = await Promise.all([api('plans.list',{...listParams([],'planOffset'),all:'1'}),api('orders.list',listParams(['status','method'],'orderOffset'))]);
  const planTable = table(rows(plans),[['plan_id','Plan'],['name','Name'],['version','Version'],['price','CNY'],['duration_days','Days'],['status','Status']],
    row=>button('plans.status',row.status === 'ACTIVE' ? 'Withdraw' : 'Publish',{plan_id:row.plan_id,status:row.status === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE'}));
  return head('Plans & orders.') + section('Plan versions',planTable + paging(rows(plans),'planOffset')) +
    section('Create a plan version',form('plans.create',field('name','Plan name','text','',{required:true,maxlength:100}) +
      field('price','Price (CNY)','number','',{min:0.01,step:0.01,required:true}) +
      field('duration_days','Duration (days)','number','',{min:1,required:true}),'Publish version')) +
    section('Orders',filter(select('status','Status',[['','All'],['PENDING','Pending'],['PAID','Paid'],['CANCELLED','Cancelled']],query.get('status'),false) +
      select('method','Method',[['','All'],['OFFLINE','Pay at club'],['SIMULATED','Simulated']],query.get('method'),false)) + orderList(rows(orders)) + paging(rows(orders),'orderOffset'));
}
