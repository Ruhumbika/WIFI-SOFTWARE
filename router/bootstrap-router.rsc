# RJAY Hotspot - LOCAL DEVELOPMENT RouterOS setup
# Router: hAP ac lite / RouterOS 7.20.4
# Review before pasting. Replace CHANGE_WITH_STRONG_PASSWORD.
# Existing legacy HotSpot profiles are NOT modified.

# 1) Dedicated REST group. If it already exists, update it instead of adding a duplicate.
:if ([:len [/user group find where name="rjay-api-group"]] = 0) do={
    /user group add name=rjay-api-group policy=read,write,rest-api
} else={
    /user group set [find where name="rjay-api-group"] policy=read,write,rest-api
}

# 2) Dedicated REST user, restricted to the HotSpot LAN.
# IMPORTANT: the password here must be the same value used as MIKROTIK_PASSWORD in backend/.env.
:if ([:len [/user find where name="rjay-api"]] = 0) do={
    /user add name=rjay-api group=rjay-api-group address=10.10.1.0/24 password="CHANGE_WITH_STRONG_PASSWORD"
} else={
    /user set [find where name="rjay-api"] group=rjay-api-group address=10.10.1.0/24 password="CHANGE_WITH_STRONG_PASSWORD" disabled=no
}

# 3) LOCAL TEST ONLY.
# HotSpot owns/intercepts normal HTTP on port 80 on the HotSpot LAN. Put WebFig/REST on 8081
# so /rest requests do not collide with the captive portal. Your existing HotSpot proxy uses 8080,
# therefore RJAY intentionally uses 8081 here.
/ip service set www disabled=no address=10.10.1.0/24 port=8081

# Verify from the Ubuntu PC BEFORE running Laravel bootstrap:
# curl -i -u 'rjay-api:CHANGE_WITH_STRONG_PASSWORD' http://10.10.1.1:8081/rest/system/resource
# Expected: HTTP 200 + RouterOS JSON.
