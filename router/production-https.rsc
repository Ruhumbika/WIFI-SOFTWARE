# Production direction: use HTTPS REST instead of HTTP.
# Review current /certificate and /ip service configuration first.
/certificate add name=rjay-rest-cert common-name=10.10.1.1 key-usage=tls-server
/certificate sign rjay-rest-cert
/ip service set www-ssl disabled=no certificate=rjay-rest-cert address=10.10.1.0/24
# After the application is confirmed to use https://10.10.1.1/rest:
# /ip service set www disabled=yes
