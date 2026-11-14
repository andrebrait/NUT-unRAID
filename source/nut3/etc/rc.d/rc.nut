#!/bin/bash
#
# Copyright Derek Macias (parts of code from NUT package)
# Copyright macester (parts of code from NUT package)
# Copyright gfjardim (parts of code from NUT package)
# Copyright SimonF (parts of code from NUT package)
# Copyright Lime Technology (any and all other parts of Unraid)
#
# Copyright V'yacheslav Stetskevych (as original script author)
# Copyright desertwitch (co-author and maintainer of this file)
#
# This program is free software; you can redistribute it and/or
# modify it under the terms of the GNU General Public License 2
# as published by the Free Software Foundation.
#
# The above copyright notice and this permission notice shall be
# included in all copies or substantial portions of the Software.
#

DRIVERPATH=/usr/libexec/nut
export PATH=$PATH:$DRIVERPATH

NUT_QUIET_INIT_UPSNOTIFY=true
export NUT_QUIET_INIT_UPSNOTIFY

NUT_DEBUG_PID=true
export NUT_DEBUG_PID

PLGPATH="/boot/config/plugins/nut3"
CONFIG=$PLGPATH/nut3.cfg
DOCROOT="/usr/local/emhttp/plugins/nut3"

# serialize service and config changes; shutdown paths (rc.6) stay lock-free.
# -o keeps the lock fd out of the daemons this script starts.
case "$1" in
    start|start_upsmon|stop|reload|restart|write_config)
        if [ -z "$RCNUT_LOCKED" ]; then
            RCNUT_LOCKED=1 exec flock -o -w 180 /var/run/rc.nut.lock "$0" "$@"
        fi
        ;;
esac
# the guard is for this process only; it must not leak into the daemons and hooks started below
unset RCNUT_LOCKED

# read our configuration
[ -e "$CONFIG" ] && source $CONFIG

# scheduled battery self-test: cron file for nut-batttest, removed when the schedule is off
write_batttest_cron() {
    local f=/boot/config/plugins/dynamix/nut-batttest.cron h="${BATTTESTHOUR:-3}" wd="${BATTTESTWDAY:-0}" md="${BATTTESTMDAY:-1}" line=""
    [[ "$h" =~ ^([0-9]|1[0-9]|2[0-3])$ ]] || h=3
    [[ "$wd" =~ ^[0-6]$ ]] || wd=0
    [[ "$md" =~ ^([1-9]|1[0-9]|2[0-8])$ ]] || md=1
    case "$BATTTEST" in
        weekly)  line="0 $h * * $wd /usr/sbin/nut-batttest > /dev/null 2>&1" ;;
        monthly) line="0 $h $md * * /usr/sbin/nut-batttest > /dev/null 2>&1" ;;
    esac
    if [ -n "$line" ]; then
        # only touch the flash (and cron) when the schedule changed
        if [ "$(cat "$f" 2>/dev/null)" != "$(printf '# NUT scheduled battery self-test\n%s' "$line")" ]; then
            printf '# NUT scheduled battery self-test\n%s\n' "$line" > "$f"
            update_cron
        fi
    elif [ -f "$f" ]; then
        rm -f "$f"
        update_cron
    fi
}

# true if the driver for UPS $1 (default: the GUI-configured UPS name) is running
monitored_driver_running() {
    local pidfile
    for pidfile in /var/run/nut/*-"${1:-$NAME}".pid; do
        [ -e "$pidfile" ] && kill -0 "$(cat "$pidfile" 2>/dev/null)" 2>/dev/null && return 0
    done
    return 1
}

# UPS section names of ups.conf, one per line
ups_sections() {
    sed -n 's/^[[:space:]]*\[\([^]][^]]*\)\].*/\1/p' /etc/nut/ups.conf 2>/dev/null
}

# retry drivers that are not running yet (USB devices can be slow to appear after boot or an outage)
# fails if no driver can be retried at all (no or unreadable ups.conf, no UPS section): nothing was started
retry_drivers() {
    local try ups missing sections
    mapfile -t sections < <(ups_sections)
    [ "${#sections[@]}" -gt 0 ] || return 1
    for try in 1 2 3; do
        missing=()
        for ups in "${sections[@]}"; do
            monitored_driver_running "$ups" || missing+=("$ups")
        done
        [ "${#missing[@]}" -eq 0 ] && return 0
        echo "NUT driver(s) not running: ${missing[*]}, retrying in 10 seconds (attempt ${try}/3)..."
        sleep 10
        for ups in "${missing[@]}"; do
            /usr/sbin/upsdrvctl -u root -- start "$ups"
        done
    done
    for ups in "${sections[@]}"; do
        monitored_driver_running "$ups" || return 1
    done
    return 0
}

error_at_start() {
    if [ -d $DOCROOT/misc ]; then
        touch $DOCROOT/misc/start-failed
    fi
    exit 1
}

start_driver() {
    if ! /usr/sbin/upsdrvctl -u root start && ! retry_drivers; then
        # upsdrvctl fails if any one driver fails. Only abort when no driver
        # is running at all; otherwise carry on so upsd and upsmon still
        # protect the system with the UPS devices that did come up.
        if ! pgrep -f "^$DRIVERPATH/" >/dev/null 2>&1; then
            if [ -x /usr/local/emhttp/webGui/scripts/notify ]; then
                timeout -s9 15 /usr/local/emhttp/webGui/scripts/notify -e "NUT" -s "NUT: UPS driver failed to start" -d "No UPS driver started; this system will not shut down on power loss. Check the SYSLOG." -i alert >/dev/null 2>&1
            fi
            error_at_start
        fi
        echo "WARNING: NUT failed to start one or more UPS drivers, continuing with the drivers that did start."
        SUBJECT="NUT: UPS driver failed to start"
        DESCRIPTION="One or more UPS drivers failed to start; NUT is running with the remaining drivers. Check the SYSLOG."
        LEVEL="warning"
        # upsmon still starts (so NOCOMM alerts fire), but say loudly that shutdown protection is gone
        if ! monitored_driver_running; then
            echo "ERROR: The driver for the monitored UPS [${NAME}] is not running, this system is NOT protected by NUT."
            SUBJECT="NUT: monitored UPS [${NAME}] driver failed to start"
            DESCRIPTION="The driver for the monitored UPS [${NAME}] is not running, so this system will NOT shut down on power loss. Check the SYSLOG."
            LEVEL="alert"
        fi
        if [ -x /usr/local/emhttp/webGui/scripts/notify ]; then
            timeout -s9 15 /usr/local/emhttp/webGui/scripts/notify -e "NUT" -s "$SUBJECT" -d "$DESCRIPTION" -i "$LEVEL" >/dev/null 2>&1
        fi
    fi
}

start_upsd() {
    if pgrep -x upsd >/dev/null 2>&1; then
        echo "NUT upsd is running..."
    else
        /usr/sbin/upsd -u root || error_at_start
    fi
}

start_upsmon() {
    if pgrep -x upsmon >/dev/null 2>&1; then
        echo "NUT upsmon is running..."
    else
        /usr/sbin/upsmon -p || error_at_start
    fi
}

start() {
    if [ "$ORUSBPOWER" == "enable" ]; then
        echo "WARNING: NUT was user-configured to disable power management for all USB devices."
        echo "WARNING: NUT is now forcing all USB devices to permanent [on] power state as requested..."

        OVRESULT="$(echo on | tee /sys/bus/usb/devices/*/power/control)"
        if [ "$OVRESULT" != "on" ]; then
            echo "ERROR: NUT has encountered an unexpected result disabling all USB power management: ${OVRESULT}"
        fi
    fi
    if [ "$MODE" != "slave" ]; then
        start_driver
        sleep 1
        start_upsd
    fi
    start_upsmon
}

stop() {
    echo "Stopping the NUT services... "
    if pgrep -x upsmon >/dev/null 2>&1; then
        /usr/sbin/upsmon -c stop

        TIMER=0
        while killall upsmon 2>/dev/null; do
            sleep 1
            killall upsmon
            TIMER=$((TIMER+1))
            if [ $TIMER -ge 30 ]; then
                killall -9 upsmon
                sleep 1
                break
            fi
        done
    fi

    if pgrep -x upsd >/dev/null 2>&1; then
        /usr/sbin/upsd -c stop

        TIMER=0
        while killall upsd 2>/dev/null; do
            sleep 1
            killall upsd
            TIMER=$((TIMER+1))
            if [ $TIMER -ge 30 ]; then
                killall -9 upsd
                sleep 1
                break
            fi
        done
    fi

    # on-battery workers and upssched timers must not outlive the services (a stale worker can still set FSD)
    # anchored on the command line start: a bare substring would also kill e.g. an ssh or bash -c that merely mentions the path
    pkill -f -- '^((/usr)?/bin/bash[[:space:]]+|bash[[:space:]]+)?/usr/sbin/nut-notify([[:space:]]|$)' 2>/dev/null
    pkill -x upssched 2>/dev/null
    rm -f /var/run/nut/upssched.pipe /var/run/nut/upssched.lock

    sleep 2

    # remove pid from old package
    if [ -f /var/run/upsmon.pid ]; then
        rm -f /var/run/upsmon.pid
    fi

    if [ -f /var/run/nut/upsmon.pid ]; then
        rm -f /var/run/nut/upsmon.pid
    fi

    /usr/sbin/upsdrvctl stop
}

check_down() {
    for chkfile in /var/run/nut/*.pid; do
        [ -e "$chkfile" ] || continue
        chkpid=$(cat "$chkfile" 2>/dev/null)

        if [[ $chkpid =~ ^[0-9]+$ ]]; then
            kill -0 "$chkpid" &>/dev/null && return 1
        fi
    done

    return 0
}

backup_logs() {
    if [ "$SYSLOGBACKUP" == "enable" ]; then
        if [ "$SYSLOGMETHOD" == "file" ] || [ "$SYSLOGMETHOD" == "both" ]; then
            if [ -f /var/log/nut.log ]; then
                echo "Backing up last 200KB of NUT service logs to USB..."
                if ! tail -c 200k /var/log/nut.log > /tmp/nut.log; then
                    echo "Failed to back up last 200KB of NUT service logs to USB - tail operation failed."
                    rm -f /tmp/nut.log; rm -f /boot/logs/nut.log
                elif ! mv -f /tmp/nut.log /boot/logs/nut.log; then
                    echo "Failed to back up last 200KB of NUT service logs to USB - move operation failed."
                    rm -f /tmp/nut.log; rm -f /boot/logs/nut.log
                fi
            fi
        fi
        if [ "$SYSLOGFILTER" == "enable" ]; then
            if [ -f /var/log/nut-spam ]; then
                echo "Backing up last 200KB of NUT spam logs to USB..."
                if ! tail -c 200k /var/log/nut-spam > /tmp/nut-spam; then
                    echo "Failed to back up last 200KB of NUT spam logs to USB - tail operation failed."
                    rm -f /tmp/nut-spam; rm -f /boot/logs/nut-spam
                elif ! mv -f /tmp/nut-spam /boot/logs/nut-spam; then
                    echo "Failed to back up last 200KB of NUT spam logs to USB - move operation failed."
                    rm -f /tmp/nut-spam; rm -f /boot/logs/nut-spam
                fi
            fi
        fi
    fi
}

# insert $2 into rc.6 after the last line matching $1, then verify it is really there
add_rc6_hook() {
    grep -qF "$2" /etc/rc.d/rc.6 && return 0
    echo "Adding '$2' to rc.6 for NUT..."
    awk -v anchor="$1" -v hook="$2" '
    $0 ~ anchor { last=NR }
    { lines[NR]=$0 }
    END {
        for (i=1; i<=NR; i++) {
            print lines[i]
            if (i==last) print hook
        }
    }' /etc/rc.d/rc.6 > /etc/rc.d/rc.6.new && cat /etc/rc.d/rc.6.new > /etc/rc.d/rc.6
    rm -f /etc/rc.d/rc.6.new
    if ! grep -qF "$2" /etc/rc.d/rc.6; then
        echo "ERROR: NUT could not add '$2' to /etc/rc.d/rc.6 (no line matching '$1' found)."
        echo "ERROR: Powering off the UPS after a NUT-initiated shutdown will NOT work on this Unraid version."
        logger -p daemon.err -t "rc.nut" "ERROR: could not add '$2' to /etc/rc.d/rc.6 (anchor '$1' not found), UPS power-off after shutdown will not work"
        return 1
    fi
}

# generate the NUT configuration files from the settings onto the flash drive
# (or keep using a previous configuration whose imported settings await review)
generate_config() {
    if ! /usr/bin/php $DOCROOT/scripts/managed_config write; then
        echo "ERROR: The NUT settings are invalid, so the NUT configuration was not updated and NUT will not start."
        echo "ERROR: Correct the problems shown on the NUT Settings page."
        return 1
    fi
}

write_config() {
    echo "Writing NUT configuration..."
    local RETVAL=0

    generate_config || RETVAL=1

    # the flash drive holds the generated files and any user files (e.g. xnut-notify-hooks.sh)
    mkdir -p /etc/nut
    (umask 027; cp -rf $PLGPATH/ups/* /etc/nut/ >/dev/null 2>&1)

    # re-create state directories if missing
    [ ! -d /var/state/ups ] && mkdir -p /var/state/ups
    [ ! -d /var/run/nut ] && mkdir -p /var/run/nut

    # update permissions
    if [ -d /etc/nut ]; then
        echo "Updating permissions for NUT..."
        chown root:nut /etc/nut
        chmod 750 /etc/nut
        chown root:nut /etc/nut/*
        chmod 640 /etc/nut/*
        chmod +x /etc/nut/*.sh
        chown root:nut /var/run/nut
        chmod 770 /var/run/nut
        chown root:nut /var/state/ups
        chmod 770 /var/state/ups
    fi

    # Link shutdown scripts for poweroff in rc.6
    add_rc6_hook 'remount,ro' '[ -x /etc/rc.d/rc.nut ] && /etc/rc.d/rc.nut restart_udev'
    add_rc6_hook '# Now halt' '[ -x /etc/rc.d/rc.nut ] && /etc/rc.d/rc.nut shutdown'

    RESTARTSYSLOG="NO"

    # Handle backing up and restoring logs to and from the USB drive
    if [ "$SYSLOGBACKUP" == "enable" ]; then
        if [ "$( grep -ic "/etc/rc.d/rc.nut backup_logs" /etc/rc.d/rc.local_shutdown )" -eq 0 ]; then
            echo "Adding log backup line to rc.local_shutdown for NUT..."
            sed -i '/# Get time-out setting/i [ -x /etc/rc.d/rc.nut ] && /etc/rc.d/rc.nut backup_logs | logger -t "rc.nut"' /etc/rc.d/rc.local_shutdown
        fi

        # If none exist - restore any previous logs from the USB drive
        if [ "$SYSLOGMETHOD" == "file" ] || [ "$SYSLOGMETHOD" == "both" ]; then
            if [ ! -f /var/log/nut.log ] && [ -f /boot/logs/nut.log ]; then
                echo "Restoring previous NUT service logs from USB..."
                if ! mv -f /boot/logs/nut.log /var/log/nut.log; then
                    echo "Failed to restore previous NUT service logs from USB... move operation failed."
                    rm -f /boot/logs/nut.log; rm -f /var/log/nut.log
                else
                    chown root:root /var/log/nut.log
                    chmod 644 /var/log/nut.log
                fi
                RESTARTSYSLOG="YES"
            fi
        else
            [ -f /boot/logs/nut.log ] && rm -f /boot/logs/nut.log
        fi

        if [ "$SYSLOGFILTER" == "enable" ]; then
            if [ ! -f /var/log/nut-spam ] && [ -f /boot/logs/nut-spam ]; then
                echo "Restoring previous NUT spam logs from USB..."
                if ! mv -f /boot/logs/nut-spam /var/log/nut-spam; then
                    echo "Failed to restore previous NUT spam logs from USB... move operation failed."
                    rm -f /boot/logs/nut-spam; rm -f /var/log/nut-spam
                else
                    chown root:root /var/log/nut-spam
                    chmod 644 /var/log/nut-spam
                fi
                RESTARTSYSLOG="YES"
            fi
        else
            [ -f /boot/logs/nut-spam ] && rm -f /boot/logs/nut-spam
        fi

    else
        [ -f /boot/logs/nut.log ] && rm -f /boot/logs/nut.log
        [ -f /boot/logs/nut-spam ] && rm -f /boot/logs/nut-spam
    fi

    # NUT Rule-Based Repetitive Message Filtering (SYSLOG Anti-Spam Module)
    if [ "$SYSLOGFILTER" == "enable" ]; then
        if [ -f /etc/nut/xnut-nospam.conf ]; then
            if [ -f /etc/rsyslog.d/xnut-nospam.conf ] && [ ! -f /etc/rsyslog.d/98-nut-nospam.conf ]; then
                mv -f /etc/rsyslog.d/xnut-nospam.conf /etc/rsyslog.d/98-nut-nospam.conf
                chmod 644 /etc/rsyslog.d/98-nut-nospam.conf
                RESTARTSYSLOG="YES"
            fi
            if [ ! -f /etc/rsyslog.d/98-nut-nospam.conf ] || ! cmp -s /etc/nut/xnut-nospam.conf /etc/rsyslog.d/98-nut-nospam.conf; then
                echo "Adding NUT rule-based message filters to SYSLOG configuration..."
                cp -f /etc/nut/xnut-nospam.conf /etc/rsyslog.d/98-nut-nospam.conf
                chmod 644 /etc/rsyslog.d/98-nut-nospam.conf
                RESTARTSYSLOG="YES"
            fi
            if [ ! -f /etc/logrotate.d/nut-spam ] || ! cmp -s $DOCROOT/misc/nut-spam /etc/logrotate.d/nut-spam; then
                cp -f $DOCROOT/misc/nut-spam /etc/logrotate.d/nut-spam
                chmod 644 /etc/logrotate.d/nut-spam
                RESTARTSYSLOG="YES"
            fi
        fi
    else
        if [ -f /etc/rsyslog.d/98-nut-nospam.conf ]; then
            echo "Removing NUT rule-based message filters from SYSLOG configuration..."
            rm -f /etc/rsyslog.d/98-nut-nospam.conf
            RESTARTSYSLOG="YES"
        fi
        if [ -f /etc/rsyslog.d/xnut-nospam.conf ]; then
            echo "Removing NUT rule-based message filters from SYSLOG configuration..."
            rm -f /etc/rsyslog.d/xnut-nospam.conf
            RESTARTSYSLOG="YES"
        fi
        if [ -f /etc/logrotate.d/nut-spam ]; then
            rm -f /etc/logrotate.d/nut-spam
            RESTARTSYSLOG="YES"
        fi
    fi

    # Redirection for NUT Service Logs
    if [ "$SYSLOGMETHOD" == "file" ]; then
        if [ -f /etc/rsyslog.d/99-nut-to-both.conf ]; then
            rm -f /etc/rsyslog.d/99-nut-to-both.conf
            RESTARTSYSLOG="YES"
        fi
        if [ ! -f /etc/logrotate.d/nut ] || ! cmp -s $DOCROOT/misc/nut /etc/logrotate.d/nut; then
            cp -f $DOCROOT/misc/nut /etc/logrotate.d/nut
            chmod 644 /etc/logrotate.d/nut
            RESTARTSYSLOG="YES"
        fi
        if [ ! -f /etc/rsyslog.d/99-nut-to-file.conf ] || ! cmp -s $DOCROOT/misc/99-nut-to-file.conf /etc/rsyslog.d/99-nut-to-file.conf; then
            cp -f $DOCROOT/misc/99-nut-to-file.conf /etc/rsyslog.d/99-nut-to-file.conf
            chmod 644 /etc/rsyslog.d/99-nut-to-file.conf
            RESTARTSYSLOG="YES"
        fi
    elif [ "$SYSLOGMETHOD" == "both" ]; then
        if [ -f /etc/rsyslog.d/99-nut-to-file.conf ]; then
            rm -f /etc/rsyslog.d/99-nut-to-file.conf
            RESTARTSYSLOG="YES"
        fi
        if [ ! -f /etc/logrotate.d/nut ] || ! cmp -s $DOCROOT/misc/nut /etc/logrotate.d/nut; then
            cp -f $DOCROOT/misc/nut /etc/logrotate.d/nut
            chmod 644 /etc/logrotate.d/nut
            RESTARTSYSLOG="YES"
        fi
        if [ ! -f /etc/rsyslog.d/99-nut-to-both.conf ] || ! cmp -s $DOCROOT/misc/99-nut-to-both.conf /etc/rsyslog.d/99-nut-to-both.conf; then
            cp -f $DOCROOT/misc/99-nut-to-both.conf /etc/rsyslog.d/99-nut-to-both.conf
            chmod 644 /etc/rsyslog.d/99-nut-to-both.conf
            RESTARTSYSLOG="YES"
        fi
    else
        if [ -f /etc/logrotate.d/nut ]; then
            rm -f /etc/logrotate.d/nut
            RESTARTSYSLOG="YES"
        fi
        if [ -f /etc/rsyslog.d/99-nut-to-both.conf ]; then
            rm -f /etc/rsyslog.d/99-nut-to-both.conf
            RESTARTSYSLOG="YES"
        fi
        if [ -f /etc/rsyslog.d/99-nut-to-file.conf ]; then
            rm -f /etc/rsyslog.d/99-nut-to-file.conf
            RESTARTSYSLOG="YES"
        fi
    fi

    if [ "$RESTARTSYSLOG" == "YES" ]; then
        echo "Restarting SYSLOG daemon due to configurational changes for NUT..."
        sleep 1
        /etc/rc.d/rc.rsyslogd restart
        sleep 1
    fi

    write_batttest_cron

    # NUT Runtime Statistics Module
    /etc/rc.d/rc.nutstats check

    return $RETVAL
}

# UPS names to power off: the configured UPS plus every local UPS that upsmon.conf monitors with a power value above 0
# (legacy redundant setups, any role); UPSes with power value 0 are only watched and stay on, remote systems are not ours.
# The word splitting follows NUT's parseconf (quotes, backslash escapes, # comments); malformed lines are skipped.
monitored_power_ups() {
    awk '
    function words(line,    i, ch, st, have, cur) {
        nw = 0; st = 0; have = 0
        for (i = 1; i <= length(line); i++) {
            ch = substr(line, i, 1)
            if (st == 0) {
                if (ch == "#") break
                if (ch ~ /[ \t\r\f\v]/) continue
                if (ch == "=") { w[++nw] = "="; continue }
                cur = ""; have = 1
                if (ch == "\"") st = 3
                else if (ch == "\\") st = 2
                else { cur = ch; st = 1 }
            } else if (st == 1) {
                if (ch == "#") { w[++nw] = cur; have = 0; break }
                if (ch ~ /[ \t\r\f\v=]/) { w[++nw] = cur; if (ch == "=") w[++nw] = "="; have = 0; st = 0; continue }
                if (ch == "\\") st = 2
                else cur = cur ch
            } else if (st == 2) {
                cur = cur ch; st = 1
            } else if (st == 3) {
                if (ch == "#") return 0
                if (ch == "\"") { w[++nw] = cur; have = 0; st = 0 }
                else if (ch == "\\") st = 4
                else cur = cur ch
            } else {
                cur = cur ch; st = 3
            }
        }
        if (st == 2 || st >= 3) return 0
        if (have) w[++nw] = cur
        return 1
    }
    {
        if (!words($0) || nw < 6 || toupper(w[1]) != "MONITOR") next
        if (w[3] !~ /^[0-9]+$/ || w[3] + 0 == 0) next
        at = index(w[2], "@")
        if (at < 2) next
        if (tolower(substr(w[2], at + 1)) ~ /^((127\.0\.0\.1|localhost|\[::1\])(:[0-9]+)?|::1)$/) print substr(w[2], 1, at - 1)
    }' /etc/nut/upsmon.conf 2>/dev/null
}

# power off the UPS inverters; each name goes to upsdrvctl on its own, never an empty one (that would mean every driver)
shutdown_ups() {
    local ups rc=0 seen=" " monitored=()
    mapfile -t monitored < <(monitored_power_ups)
    for ups in "$NAME" "${monitored[@]}"; do
        if ! [[ "$ups" =~ ^[A-Za-z0-9._-]+$ ]]; then
            echo "ERROR: Cannot shut down the UPS: invalid UPS name [${ups}]." | logger -t "rc.nut"
            rc=1
            continue
        fi
        [[ "$seen" == *" $ups "* ]] && continue
        seen="$seen$ups "
        echo "NUT is shutting down the UPS inverter [${ups}]..."
        # "--": a name with a leading hyphen is never read as an option
        /usr/sbin/upsdrvctl -u root -- shutdown "$ups" || rc=1
    done
    return $rc
}

case "$1" in
    shutdown) # shuts down the UPS driver(s)
        if [ -f /etc/nut/killpower ] && [ "$MODE" != "slave" ]; then
            shutdown_ups || exit 1
        fi
        ;;
    start)  # starts everything (for a ups server box)
        sleep 1
        write_config || error_at_start
        sleep 1
        start
        ;;
    start_upsmon) # starts upsmon only (for a ups client box)
        sleep 1
        write_config || error_at_start
        sleep 1
        start_upsmon
        ;;
    stop) # stops all UPS-related daemons
        sleep 1
        stop
        sleep 1
        write_config
        check_down
        ;;
    reload)
        sleep 1
        write_config || exit 1
        sleep 1
        if [ "$MODE" != "slave" ]; then
            start_driver
            sleep 1
            /usr/sbin/upsd -c reload
        fi
        /usr/sbin/upsmon -c reload
        ;;
    restart)
        sleep 1
        # invalid settings keep the running services; /etc/nut still holds the files they were started with
        generate_config || error_at_start
        sleep 1
        stop # with the old configuration: upsdrvctl stop must see the drivers that are running
        sleep 1
        write_config || error_at_start
        sleep 3
        start
        ;;
    restart_udev)
        if [ -f /etc/nut/killpower ]; then
            echo "Restarting udev for NUT to be able to shut the UPS inverter off..."
            /etc/rc.d/rc.udev start
            sleep 10
        fi
        ;;
    write_config)
        write_config
        ;;
    backup_logs)
        backup_logs
        ;;
    *)
    echo "Usage: $0 {start|start_upsmon|stop|shutdown|reload|restart|write_config}"
esac
