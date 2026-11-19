"""Exercise a running Apache instance configured with a fresh demo seed in a disposable DB.

python tests/http_workflows.py http://127.0.0.1:8092
"""
import concurrent.futures
import datetime
import http.cookiejar
import json
import sys
import threading
import urllib.error
import urllib.parse
import urllib.request
import uuid


class Client:
    def __init__(self, base):
        self.base = base.rstrip("/") + "/api.php?"
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar())
        )
        self.token = ""
        self.token = self.call("auth.session")["csrf_token"]

    def call(self, action, body=None, expected=200, query=None, csrf=True):
        url = self.base + urllib.parse.urlencode({"action": action, **(query or {})})
        headers = {"Content-Type": "application/json"}
        if csrf:
            headers["X-CSRF-Token"] = self.token
        req = urllib.request.Request(url, None if body is None else json.dumps(body).encode(), headers)
        try:
            result = self.opener.open(req, timeout=20)
        except urllib.error.HTTPError as err:
            result = err
        with result:
            payload = json.load(result)
        assert result.status == expected, (action, result.status, payload)
        assert payload["ok"] == (expected == 200) and payload["db_ms"] >= 0, (action, payload)
        if not payload["ok"]:
            return payload["error"]["code"]
        data = payload["data"]
        if isinstance(data, dict) and "csrf_token" in data:
            self.token = data["csrf_token"]
        return data


def parallel(clients, action, bodies):
    barrier = threading.Barrier(len(clients))

    def submit(pair):
        client, body = pair
        barrier.wait(5)
        return client.call(action, body)

    with concurrent.futures.ThreadPoolExecutor(max_workers=len(clients)) as pool:
        return list(pool.map(submit, zip(clients, bodies)))


def check(base):
    member, admin, other, guest = [Client(base) for _ in range(4)]
    unique = uuid.uuid4().hex
    credentials = {"email": "qa-" + unique + "@form.test", "password": "Workflow123!"}
    registration = {**credentials, "full_name": "HTTP test member", "phone": "123456789"}
    assert guest.call("orders.list", expected=401) == "LOGIN_REQUIRED"
    assert guest.call("auth.register", registration, expected=403, csrf=False) == "CSRF"
    uid = member.call("auth.register", {**registration, "role": "ADMIN"})["user"]["user_id"]
    assert guest.call("auth.register", registration, expected=409) == "DUPLICATE"
    assert guest.call("auth.login", {**credentials,"password":"wrongpass"}, expected=401) == "LOGIN_FAILED"
    admin.call("auth.login", {"email":"admin@form.test","password":"FormClub2026!"})
    other.call("auth.login", credentials)
    assert member.call("members.list", expected=403) == "FORBIDDEN"
    assert admin.call("members.list", query={"q":credentials["email"]})[0]["user_id"] == uid
    print("PASS registration, CSRF, unique email, roles, database member search")

    def create(plan=1, method="SIMULATED"):
        request = {"plan_id":plan,"method":method,"request_key":uuid.uuid4().hex}
        first = member.call("orders.create", request)["order"]["order_id"]
        assert first == member.call("orders.create", request)["order"]["order_id"]
        assert member.call("orders.create", {**request,"plan_id":3}, expected=409) == "KEY_REUSED"
        return first

    a = create()
    failed = member.call("orders.pay", {"order_id":a,"result":"FAILED","attempt_key":"failed"})
    assert failed["order"]["status"] == "PENDING" and failed["membership"] is None
    assert len(member.call("orders.pay", {"order_id":a,"result":"FAILED","attempt_key":"failed"})["payments"]) == 1
    d = parallel([member,other], "orders.pay", [{"order_id":a}]*2)
    assert d[0]["membership"]["membership_id"] == d[1]["membership"]["membership_id"]
    assert sum(p["status"]=="SUCCEEDED" for p in d[0]["payments"]) == 1
    b, c = create(), create(2)
    settled = parallel([member,other], "orders.pay", [{"order_id":b},{"order_id":c}])
    periods = sorted([x["membership"] for x in settled], key=lambda p:p["starts_at"])
    assert periods[0]["starts_at"] == d[0]["membership"]["ends_at"]
    assert periods[1]["starts_at"] == periods[0]["ends_at"]
    print("PASS failed/repeated payment, simultaneous confirmation, simultaneous renewals")
    offline = create(method="OFFLINE")
    assert member.call("orders.confirm", {"order_id":offline}, expected=403) == "FORBIDDEN"
    assert member.call("orders.pay", {"order_id":offline}, expected=409) == "WRONG_METHOD"
    assert admin.call("orders.confirm", {"order_id":offline})["order"]["status"] == "PAID"
    abandoned = create()
    assert member.call("orders.cancel", {"order_id":abandoned})["order"]["status"] == "CANCELLED"
    assert member.call("orders.pay", {"order_id":abandoned}, expected=409) == "ORDER_CANCELLED"
    guest.call("auth.login", {"email":"new@form.test","password":"FormClub2026!"})
    assert guest.call("orders.detail", query={"order_id":a}, expected=404) == "NOT_FOUND"
    assert guest.call("orders.pay", {"order_id":a}, expected=404) == "NOT_FOUND"
    print("PASS offline confirmation, cancellation, ownership")
    if len(sys.argv) < 3 or sys.argv[2] != "--billing-only":
        tomorrow = datetime.datetime.now(datetime.timezone(datetime.timedelta(hours=8))).date() + datetime.timedelta(days=1)
        sessions = member.call("sessions.list", query={"date":tomorrow.isoformat()})
        sid = sessions["items"][0]["session_id"]
        member.call("bookings.create", {"session_id":sid})
        roster = admin.call("sessions.roster", query={"session_id":sid})["items"]
        bid = next(r["booking_id"] for r in roster if int(r["user_id"]) == int(uid))
        member.call("bookings.cancel", {"booking_id":bid})
        assert member.call("bookings.list")["items"][0]["status"] == "CANCELLED_BY_MEMBER"
        print("PASS purchase-to-booking, roster by session, cancellation by booking")
    admin.call("members.status", {"user_id":uid,"status":"DISABLED"})
    assert member.call("memberships.list", expected=401) == "LOGIN_REQUIRED"
    assert other.call("auth.session")["user"] is None
    print("PASS disabled member loses both sessions")


if __name__ == "__main__":
    check(sys.argv[1])
