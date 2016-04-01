<?php
/* read config files */
$ipmi_cfg_file = '/boot/config/plugins/ipmi/ipmi.cfg';
$fan_cfg_file  = '/boot/config/plugins/ipmi/fan.cfg';
if (is_file($ipmi_cfg_file))
	$ipmi_cfg	= parse_ini_file($ipmi_cfg_file);
if (is_file($fan_cfg_file))
	$fan_cfg	= parse_ini_file($fan_cfg_file);

/* fan control */
$ipmifan						= isset($fan_cfg['IPMIFAN'])			? $fan_cfg['IPMIFAN']			: "disable";

/* ipmi network options */
$ipmi_network		= isset($ipmi_cfg['NETWORK'])		? $ipmi_cfg['NETWORK']		: 'disable';
$ipmi_ipaddr			= isset($ipmi_cfg['IPADDR'])			? $ipmi_cfg['IPADDR']			: '';
$ipmi_user				= isset($ipmi_cfg['USER'])					? $ipmi_cfg['USER']				: '';
$ipmi_password	= isset($ipmi_cfg['PASSWORD'])	? $ipmi_cfg['PASSWORD']	: '';

/* check if local ipmi driver is loaded */
$ipmi_mod = shell_exec("modprobe ipmi_si --first-time 2>&1 | grep -q 'Module already in kernel' && echo 1 || echo 0 2> /dev/null");

/* options for network access */
$ipmi_options = ($ipmi_network == 'enable') ? "--always-prefix -h $ipmi_ipaddr -u $ipmi_user -p ".
	base64_decode($ipmi_password)." --session-timeout=5000 --retransmission-timeout=1000" : '';
?>