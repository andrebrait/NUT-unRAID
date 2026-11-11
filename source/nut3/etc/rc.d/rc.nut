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

# read our configuration
[ -e "$CONFIG" ] && source $CONFIG

error_at_start() {
    if [ -d $DOCROOT/misc ]; then
        touch $DOCROOT/misc/start-failed
    fi
    exit 1
}

start_driver() {
    /usr/sbin/upsdrvctl -u root start || error_at_start
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

write_config() {
    echo "Writing NUT configuration..."
    local RETVAL=0

    # generate the NUT configuration files from the settings onto the flash drive
    # (or keep using a previous configuration whose imported settings await review)
    if ! /usr/bin/php $DOCROOT/scripts/managed_config write; then
        echo "ERROR: The NUT settings are invalid, so the NUT configuration was not updated and NUT will not start."
        echo "ERROR: Correct the problems shown on the NUT Settings page."
        RETVAL=1
    fi

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

    # NUT Runtime Statistics Module
    /etc/rc.d/rc.nutstats check

    return $RETVAL
}

case "$1" in
    shutdown) # shuts down the UPS driver
        if [ -f /etc/nut/killpower ]; then
            echo "NUT is shutting down the UPS inverter..."
            /usr/sbin/upsdrvctl -u root shutdown
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
        write_config
        sleep 1
        stop
        sleep 1
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
        # keep the running services if the new configuration is invalid
        write_config || error_at_start
        sleep 1
        stop
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
