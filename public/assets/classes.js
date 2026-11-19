import { admin, api, button, escape, facts, field, filter, form, guarded, head, hidden, link, listParams, paging, query, rows, section, select, state, table, view } from './ui.js';

const sessionColumns = [['session_id','Session'],['course_name','Class'],['starts_at','Starts'],['ends_at','Ends'],['coach_name','Coach'],['venue_name','Venue'],['spots_left','Spots left'],['status','Status']];
const kinds = {coach:'Coaches',venue:'Venues',course:'Course templates'};

async function resourceChoices(kind) {
  const list = rows(await api('resources.list',{kind,limit:100}));
  return list.filter(row=>row.status === 'ACTIVE').map(row=>[row[kind + '_id'],row.name]);
}

// A datalist keeps large catalogs accessible by their numeric resource IDs.
function resourceInput(kind, choices, value, required) {
  const id = kind + '-choices';
  const label = kind[0].toUpperCase() + kind.slice(1) + ' ID';
  return field(kind + '_id',label,'number',value,{min:1,list:id,required}) +
    '<datalist id="' + id + '">' + choices.map(([id,name])=>'<option value="' + escape(id) + '">' + escape(name) + '</option>').join('') + '</datalist>';
}

export async function schedulePage() {
  const managing = view === 'admin-schedule';
  if (managing && guarded('ADMIN')) return guarded('ADMIN');
  const filters = listParams(['date','course_id','coach_id','type','scope','status']);
  if (!filters.scope) filters.scope = managing ? 'all' : 'upcoming';
  const [result,coaches,courses] = await Promise.all([api('sessions.list',filters),resourceChoices('coach'),resourceChoices('course')]);
  const list = rows(result);
  const controls = filter(field('date','Class date','date',filters.date) + field('type','Class type','text',filters.type) +
    resourceInput('coach',coaches,filters.coach_id,false) + resourceInput('course',courses,filters.course_id,false) +
    select('scope','Show',[['upcoming','Upcoming'],['past','Past'],['all','All dates']],filters.scope) +
    select('status','Status',[['','All'],['SCHEDULED','Scheduled'],['CANCELLED','Cancelled'],['COMPLETED','Completed']],filters.status,false));
  if (managing) {
    return head('Schedule & check-in.') + link('Schedule a class','edit-session',{},'btn lime') + section('Class sessions', controls +
      table(list,sessionColumns,s => link('Open roster','roster',{session_id:s.session_id}) +
        (s.status === 'SCHEDULED' ? link('Edit','edit-session',{session_id:s.session_id}) : '')) + paging(list));
  }
  const cards = list.map(s => '<article class="course-card"><div class="course-art ' +
    (s.category === 'Strength' ? 'orange' : '') + '"><span class="tag">' + escape(s.category) +
    '</span><span class="activity" aria-hidden="true"></span></div><div class="course-body"><h3>' + escape(s.course_name) +
    '</h3><p>' + escape(s.coach_name) + ' · ' + escape(s.venue_name) + '</p><p class="spaced">' + escape(s.starts_at) +
    '</p><div class="course-bottom"><span class="tag">' + escape(s.status === 'SCHEDULED' ? s.spots_left + ' spots left' : s.status) + '</span>' +
    link('View class ↗','session',{session_id:s.session_id}) + '</div></div></article>').join('');
  return head('Make it a good week.','Choose a class to see the full schedule and reserve a spot.') + controls +
    (list.length ? '<div class="grid-3 spaced">' + cards + '</div>' : '<p class="club-empty">No classes match these filters.</p>') + paging(list);
}

export async function sessionPage() {
  const s = await api('sessions.detail',{session_id:query.get('session_id')});
  let action = '';
  if (admin()) action = link('Open roster','roster',{session_id:s.session_id},'btn lime');
  else if (s.can_book) action = button('bookings.create','Reserve a spot',{session_id:s.session_id});
  else if (s.booking_reason === 'LOGIN_REQUIRED' && s.status === 'SCHEDULED' && s.spots_left > 0) {
    action = link('Sign in to book','login',{next:'session',session_id:s.session_id},'btn lime');
  } else if (s.booking_reason === 'ALREADY_BOOKED') {
    action = '<p class="notice">Your place is reserved.</p>' + link('My bookings','bookings');
  } else if (s.booking_reason === 'MEMBERSHIP_COVERAGE') {
    action = '<p class="notice">Your membership must cover the entire class.</p>' + link('Explore membership plans','plans');
  } else {
    const reasons = {BOOKING_CONFLICT:'This class overlaps another booking.',CLASS_FULL:'This class is full.',
      BOOKING_WINDOW:'This class is outside the booking window.',SESSION_CLOSED:'Booking is closed for this session.'};
    action = '<p class="notice">' + escape(reasons[s.booking_reason] || 'Booking is unavailable for this session.') + '</p>';
  }
  return head(s.course_name,s.description) + '<div class="card">' +
    facts([['Session',s.session_id],['Starts',s.starts_at],['Ends',s.ends_at],['Coach',s.coach_name],['Venue',s.venue_name],['Location',s.location],['Status',s.status],
      ['Available places',s.spots_left + ' of ' + s.capacity]]) + action + '</div><div class="actions">' + link('Back to classes','schedule') + '</div>';
}

export async function bookingsPage() {
  const denial = guarded('MEMBER');
  if (denial) return denial;
  const list = rows(await api('bookings.list',listParams(['scope']))).map(booking =>
    booking.session_status === 'CANCELLED' ? {...booking, attendance_status:'Class cancelled'} : booking);
  return head('My bookings.') + filter(select('scope','Show',[['all','All bookings'],['upcoming','Upcoming'],['past','Past'],['cancelled','Cancelled']],query.get('scope') || 'all')) +
    table(list,[['booking_id','Booking'],['course_name','Class'],['starts_at','Starts'],['status','Booking'],['session_status','Session'],['attendance_status','Attendance']],
      b => {
        const details = link('View class','session',{session_id:b.session_id});
        if (b.can_cancel) return details + button('bookings.cancel','Cancel booking',{booking_id:b.booking_id});
        if (b.status === 'CONFIRMED' && b.session_status === 'SCHEDULED') {
          return details + '<span>Cancellation window closed</span>';
        }
        return details;
      }) + paging(list);
}

export async function dashboardPage() {
  const denial = guarded('ADMIN');
  if (denial) return denial;
  const d = await api('dashboard.summary');
  return head('Club overview.',d.date) + '<div class="grid-4">' + [
    ['Active members',d.active_members],["Today's classes",d.todays_classes],['Reserved / capacity',d.confirmed + ' / ' + d.capacity],
    ['Pending offline orders',d.pending_offline]].map(([label,value])=>'<div class="card"><span class="metric-label">' + escape(label) +
    '</span><p class="metric-value">' + escape(value) + '</p></div>').join('') + '</div>' +
    section("Today's timetable",table(rows(d.sessions),sessionColumns,s=>link('Roster','roster',{session_id:s.session_id}))) +
    link('Review pending orders','admin-plans',{status:'PENDING'},'btn');
}

export async function resourcesPage() {
  const denial = guarded('ADMIN');
  if (denial) return denial;
  const kind = Object.hasOwn(kinds,query.get('kind')) ? query.get('kind') : 'coach';
  const idKey = kind + '_id';
  const list = rows(await api('resources.list',{kind,...listParams()}));
  const edits = query.get('edit');
  const current = list.find(row=>String(row[idKey]) === edits) || {};
  const tabs = '<div class="club-tabs">' + Object.entries(kinds).map(([k,name])=>link(name,'resources',{kind:k},'btn outline small')).join('') + '</div>';
  const extra = {coach:[['specialty','Specialty']],venue:[['location','Location'],['capacity','Capacity']],
    course:[['category','Class type'],['duration_minutes','Duration (minutes)'],['description','Description']]}[kind];
  const extraInputs = extra.map(([key,label]) => {
    const numeric = ['capacity','duration_minutes'].includes(key);
    return field(key,label,numeric ? 'number' : 'text',current[key],numeric ? {required:true,min:1,max:65535} : {required:key === 'category',maxlength:key === 'description' ? 1000 : 150});
  }).join('');
  return head('Club resources.') + tabs + section(kinds[kind],table(list,[[idKey,'ID'],['name','Name'],...extra,['status','Status']],
    row=>link('Edit','resources',{kind,edit:row[idKey],offset:query.get('offset')})) + paging(list)) +
    section((current[idKey] ? 'Edit ' : 'Add ') + kind,form('resources.save',hidden('kind',kind) + (current[idKey] ? hidden('id',current[idKey]) : '') +
      field('name','Name','text',current.name,{required:true,maxlength:100}) + extraInputs +
      select('status','Status',[['ACTIVE','Active'],['INACTIVE','Inactive']],current.status || 'ACTIVE'),'Save ' + kind));
}

export async function editSession() {
  const denial = guarded('ADMIN');
  if (denial) return denial;
  const id = query.get('session_id');
  const [s,courses,coaches,venues] = await Promise.all([id ? api('sessions.detail',{session_id:id}) : {},resourceChoices('course'),resourceChoices('coach'),resourceChoices('venue')]);
  const dateValue = v => String(v || '').replace(' ','T');
  return head(id ? 'Edit session #' + id : 'Schedule a class.','Enter class times in Asia/Shanghai.') +
    form('sessions.save',(id ? hidden('session_id',id) : '') + resourceInput('course',courses,s.course_id,true) + resourceInput('coach',coaches,s.coach_id,true) +
      resourceInput('venue',venues,s.venue_id,true) + field('starts_at','Start','datetime-local',dateValue(s.starts_at),{required:true,step:1}) +
      field('ends_at','End','datetime-local',dateValue(s.ends_at),{required:true,step:1}) + field('capacity','Capacity','number',s.capacity,{required:true,min:1}),'Save session');
}

export async function rosterPage() {
  const denial = guarded('ADMIN');
  if (denial) return denial;
  const id = query.get('session_id');
  const result = await api('sessions.roster',{...listParams(),session_id:id});
  const s = result.session;
  const list = rows(result).map(booking =>
    booking.attendance_status === 'VOIDED' ? {...booking, attendance_status:'Voided (class cancelled)'} : booking);
  return head(s.course_name + ' · Roster','Session #' + id + ' · ' + s.starts_at + ' – ' + s.ends_at) +
    '<div class="card">' + facts([['Coach',s.coach_name],['Venue',s.venue_name],['Status',s.status],['Reserved',s.booked + ' / ' + s.capacity]]) +
    (s.status === 'SCHEDULED' ? '<div class="row-actions">' + link('Edit session','edit-session',{session_id:id},'btn outline') +
      button('sessions.complete','Complete session',{session_id:id}) + button('sessions.cancel','Cancel session',{session_id:id}) + '</div>' : '') +
    '</div>' + section('Bookings',table(list,[['booking_id','Booking'],['full_name','Member'],['booking_status','Booking status'],['attendance_status','Attendance'],['checked_at','Check-in time']],
      b => b.booking_status === 'CONFIRMED' && !b.attendance_status && s.status === 'SCHEDULED' ? button('attendance.checkin','Check in',{booking_id:b.booking_id}) : '') + paging(list));
}

export async function reportsPage() {
  const denial = guarded('ADMIN');
  if (denial) return denial;
  const r = await api('reports.summary',{from:query.get('from'),through:query.get('through')});
  return head('Operations report.') + filter(field('from','From','date',r.from) + field('through','Through (inclusive)','date',r.through),'Run report') +
    '<div class="card">' + facts([['Confirmed receipts (CNY)',r.revenue],['Paid orders',r.paid_orders],['Eligible bookings',r.eligible],['Present',r.present],['Absent',r.absent],
      ['Attendance (%)',r.attendance_rate],['Absence (%)',r.absence_rate],['Capacity booked (%)',r.booking_rate]]) + '</div>' +
    section('Plan sales',table(rows(r.plans),[['name','Plan'],['version','Version'],['orders_sold','Sold'],['revenue','Revenue (CNY)']])) +
    section('Class popularity',table(rows(r.popularity),[['category','Class type'],['eligible','Eligible bookings'],['present','Present'],['absent','Absent'],['attendance_rate','Attendance (%)']]));
}
