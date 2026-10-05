#!/usr/bin/env bash
# Firewall for ClipHunter (deploy/nftables/cliphunter.nft). Requires explicit owner approval.
#
#   apply-firewall.sh            apply now, with a dead-man switch: unless
#                                `touch /run/cliphunter-firewall-ok` is run within 120 s from a NEW
#                                SSH session (proving access still works), the rules are removed.
#   apply-firewall.sh --persist  make the (already verified) rules survive reboots.
set -euo pipefail

RULES="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/nftables/cliphunter.nft"
OK=/run/cliphunter-firewall-ok
[[ $EUID -eq 0 ]] || { echo "run as root" >&2; exit 1; }

if [[ "${1:-}" == "--persist" ]]; then
    [[ -f "$OK" ]] || { echo "confirm access first (touch $OK)" >&2; exit 1; }
    install -m 644 "$RULES" /etc/nftables-cliphunter.nft
    grep -q 'nftables-cliphunter.nft' /etc/nftables.conf || echo 'include "/etc/nftables-cliphunter.nft"' >> /etc/nftables.conf
    systemctl enable nftables
    echo "Firewall rules persisted."
    exit 0
fi

nft -c -f "$RULES"
rm -f "$OK"
nft -f "$RULES"
systemd-run --unit="cliphunter-firewall-guard-$(date +%s)" --on-active=120 \
    /bin/sh -c "[ -f $OK ] || nft delete table inet cliphunter" >/dev/null
echo "Firewall applied. Within 120 s, from a NEW SSH session run: touch $OK"
