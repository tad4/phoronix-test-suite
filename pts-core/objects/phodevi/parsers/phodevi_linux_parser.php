<?php

/*
	Phoronix Test Suite
	URLs: http://www.phoronix.com, http://www.phoronix-test-suite.com/
	Copyright (C) 2008 - 2010, Phoronix Media
	Copyright (C) 2008 - 2010, Michael Larabel
	phodevi_linux_parser.php: General parsing functions specific to Linux

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 3 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program. If not, see <http://www.gnu.org/licenses/>.
*/

class phodevi_linux_parser
{
	public static function read_sysfs_node($search, $type = "NUMERIC", $node_dir_check = null, $find_position = 1)
	{
		static $sysfs_file_cache = null;
		$arg_hash = crc32(serialize(func_get_args()));

		if(!isset($sysfs_file_cache[$arg_hash]))
		{
			$find_count = 0;

			foreach(pts_glob($search) as $sysfs_file)
			{
				if(is_array($node_dir_check))
				{
					$skip_to_next = false;
					$sysfs_dir = dirname($sysfs_file) . '/';

					foreach($node_dir_check as $node_check => $value_check)
					{
						if(!is_file($sysfs_dir . $node_check))
						{
							$skip_to_next = true;
							break;
						}
						else if($value_check !== true)
						{
							$value_check_value = pts_file_get_contents($sysfs_dir . $node_check);

							if(isset($value_check[0]) && $value_check[0] == '!')
							{
								if($value_check_value == substr($value_check, 1))
								{
									$skip_to_next = true;
									break;
								}
							}
							else if($value_check_value != $value_check)
							{
								$skip_to_next = true;
								break;
							}
						}
					}

					if($skip_to_next)
					{
						continue;
					}
				}

				$sysfs_value = pts_file_get_contents($sysfs_file);

				switch($type)
				{
					case "NUMERIC":
						if(is_numeric($sysfs_value))
						{
							$sysfs_file_cache[$arg_hash] = $sysfs_file;
						}
						break;
					case "POSITIVE_NUMERIC":
						if(is_numeric($sysfs_value) && $sysfs_value > 0)
						{
							$sysfs_file_cache[$arg_hash] = $sysfs_file;
						}
						break;
					case "NOT_EMPTY":
						if(!empty($sysfs_value))
						{
							$sysfs_file_cache[$arg_hash] = $sysfs_file;
						}
						break;
					case "NO_CHECK":
						$sysfs_file_cache[$arg_hash] = $sysfs_file;
						break;
				}

				$find_count++;
				if($find_count < $find_position)
				{
					unset($sysfs_file_cache[$arg_hash]);
				}

				if(isset($sysfs_file_cache[$arg_hash]))
				{
					break;
				}
			}

			if(!isset($sysfs_file_cache[$arg_hash]))
			{
				$sysfs_file_cache[$arg_hash] = false;
			}
		}

		return $sysfs_file_cache[$arg_hash] == false ? -1 : pts_file_get_contents($sysfs_file_cache[$arg_hash]);
	}
	public static function read_dmidecode($type, $sub_type, $object, $find_once = false, $ignore = null)
	{
		// Read Linux dmidecode
		$value = array();

		if(is_readable("/dev/mem") && pts_executable_in_path("dmidecode"))
		{
			$ignore = pts_to_array($ignore);

			$dmidecode = shell_exec("dmidecode --type " . $type . " 2>&1");
			$sub_type = "\n" . $sub_type . "\n";

			do
			{
				$sub_type_start = strpos($dmidecode, $sub_type);

				if($sub_type_start !== false)
				{
					$dmidecode = substr($dmidecode, ($sub_type_start + strlen($sub_type)));
					$dmidecode_section = substr($dmidecode, 0, strpos($dmidecode, "\n\n"));
					$dmidecode = substr($dmidecode, strlen($dmidecode_section));
					$dmidecode_elements = explode("\n", $dmidecode_section);

					$found_in_section = false;
					for($i = 0; $i < count($dmidecode_elements) && $found_in_section == false; $i++)
					{
						$dmidecode_r = pts_trim_explode(":", $dmidecode_elements[$i]);

						if($dmidecode_r[0] == $object && isset($dmidecode_r[1]) && !in_array($dmidecode_r[1], $ignore))
						{
							array_push($value, $dmidecode_r[1]);
							$found_in_section = true;
						}
					}
				}
			}
			while($sub_type_start !== false && $find_once == false);
		}

		if(count($value) == 0)
		{
			$value = false;
		}
		else if($find_once && count($value) == 1)
		{
			$value = $value[0];
		}

		return $value;
	}
	public static function read_sys_disk_speed($path, $to_read)
	{
		$speed = -1; // in MB/s

		if(is_file($path))
		{
			switch($to_read)
			{
				case "WRITE":
					$sector = 6;
					$time = 7;
					break;
				case "READ":
					$sector = 2;
					$time = 3;
					break;
				default:
					return $speed;
					break;
			}

			$start_stat = pts_trim_spaces(file_get_contents($path));
			usleep(500000);
			$end_stat = pts_trim_spaces(file_get_contents($path));

			$start_stat = explode(" ", $start_stat);
			$end_stat = explode(" ", $end_stat);

			$delta_sectors = $end_stat[$sector] - $start_stat[$sector];
			$delta_ms_spent = $end_stat[$time] - $start_stat[$time];

			// assuming 512 byte sectors
			$delta_mb = $delta_sectors * 512 / 1048576;
			$delta_seconds = $delta_ms_spent / 1000;

			$speed = $delta_seconds != 0 ? $delta_mb / $delta_seconds : 0;
		}

		return pts_math::set_precision($speed, 2);
	}
	public static function read_sys_dmi($identifier)
	{
		$dmi = false;

		if(is_dir("/sys/class/dmi/id/"))
		{
			$ignore_words = phodevi_parser::hardware_values_to_remove();

			foreach(pts_to_array($identifier) as $id)
			{
				if(is_readable("/sys/class/dmi/id/" . $id))
				{
					$dmi_file = pts_file_get_contents("/sys/class/dmi/id/" . $id);

					if(!empty($dmi_file) && !in_array(strtolower($dmi_file), $ignore_words))
					{
						$dmi = $dmi_file;
						break;
					}
				}
			}
		}

		return $dmi;
	}
	public static function read_ati_overdrive($attribute, $adapter = 0)
	{
		// Read ATI OverDrive information using aticonfig
		// OverDrive supported in fglrx 8.52+ drivers
		$value = false;

		if(pts_executable_in_path("aticonfig"))
		{
			if($attribute == "Temperature")
			{
				$info = shell_exec("aticonfig --adapter=" . $adapter . " --od-gettemperature 2>&1");

				if(($start = strpos($info, "Temperature -")) !== false)
				{
					$info = substr($info, $start + 14);
					$value = substr($info, 0, strpos($info, " C"));
				}
			}
			else if($attribute == "FanSpeed")
			{
				// Right now there is no standardized interface to get the fan speed through besides the pplib command
				$info = shell_exec("aticonfig --adapter=" . $adapter . " --pplib-cmd \"get fanspeed 0\" 2>&1");

				if(($start = strpos($info, "Fan Speed:")) !== false)
				{
					$info = substr($info, $start + 11);
					$info = substr($info, 0, strpos($info, "%"));

					if(is_numeric($info))
					{
						$value = $info;
					}
				}
			}
			else
			{
				$info = shell_exec("aticonfig --adapter=" . $adapter . " --od-getclocks 2>&1");

				if(strpos($info, "GPU") !== false)
				{
					foreach(explode("\n", $info) as $line)
					{
						$line_r = explode(":", $line);

						if(count($line_r) == 2)
						{
							$od_option = str_replace(" ", "", trim($line_r[0]));

							if($od_option == $attribute)
							{
								$od_value = pts_trim_spaces($line_r[1]);
								$od_value = str_replace(array("%"), "", $od_value);
								$od_value_r = explode(" ", $od_value);

								$value = (count($od_value_r) == 1 ? $od_value_r[0] : $od_value_r);			
							}
						}
					}
				}
			}
		}

		return $value;
	}
	public static function read_amd_pcsdb($attribute)
	{
		// Read AMD's AMDPCSDB, AMD Persistent Configuration Store Database
		static $try_aticonfig = true;
		static $is_first_read = true;
		$ati_info = null;

		if($try_aticonfig)
		{
			if(pts_executable_in_path("aticonfig"))
			{
				$info = shell_exec("aticonfig --get-pcs-key=" . $attribute . " 2>&1");

				if($is_first_read && strpos($info, "No supported adapters") != false)
				{
					$try_aticonfig = false;
				}
				else
				{
					if(($pos = strpos($info, ":")) > 0 && strpos($info, "Error") === false)
					{
						$ati_info = substr($info, $pos + 2);
						$ati_info = substr($ati_info, 0, strpos($ati_info, " "));
					}
				}
			}
			else
			{
				$try_aticonfig = false;
			}

			$is_first_read = false;
		}

		if($ati_info == null && is_file("/etc/ati/amdpcsdb"))
		{
			// Using aticonfig --get-pcs-key failed, switch to the PTS direct parser of AMDPCSDB
			$ati_info = phodevi_linux_parser::read_amd_pcsdb_direct_parser($attribute);
		}

		return $ati_info;
	}
	public static function read_amd_pcsdb_direct_parser($attribute, $find_once = false)
	{
		// Read AMD's AMDPCSDB, AMD Persistent Configuration Store Database but using our own internal parser instead of relying upon aticonfig
		$amdpcsdb_file = "";
		$last_found_section_count = -1;
		$this_section_count = 0;
		$attribute_values = array();
		$attribute = explode(",", $attribute);

		if(count($attribute) == 2)
		{
			$attribute_prefix = array_reverse(explode("/", $attribute[0]));
			$attribute_key = $attribute[1];
			$is_in_prefix = false;

			if(is_file("/etc/ati/amdpcsdb"))
			{
				$amdpcsdb_file = explode("\n", file_get_contents("/etc/ati/amdpcsdb"));
			}

			for($l = 0; $l < count($amdpcsdb_file) && ($find_once == false || $last_found_section_count == -1); $l++)
			{
				$line = trim($amdpcsdb_file[$l]);

				if(substr($line, 0, 1) == "[" && substr($line, -1) == "]")
				{
					// AMDPCSDB Header
					$prefix_matches = true;
					$header = array_reverse(explode("/", substr($line, 1, -1)));

					for($i = 0; $i < count($attribute_prefix) && $i < count($header) && $prefix_matches; $i++)
					{
						if($attribute_prefix[$i] != $header[$i] && !pts_proximity_match($attribute_prefix[$i], $header[$i]))
						{
							$prefix_matches = false;
						}
					}

					if($prefix_matches)
					{
						$is_in_prefix = true;
						$this_section_count++;
					}
					else
					{
						$is_in_prefix = false;
					}
				}
				else if($is_in_prefix && $this_section_count != $last_found_section_count && count(($key_components = explode("=", $line))) == 2)
				{
					// AMDPCSDB Value
					if($key_components[0] == $attribute_key)
					{
						$value_type = substr($key_components[1], 0, 1);
						$value = substr($key_components[1], 1);

						switch($value_type)
						{
							case "V":
								// Value
								if(is_numeric($value) && strlen($value) < 9)
								{
									$value = dechex($value);
									$value = "0x" . str_repeat(0, 8 - strlen($value)) . strtoupper($value);						
								}
							break;
							case "R":
								// Raw
							break;
							case "S":
								// String
							break;

						}
						array_push($attribute_values, $value);
						$last_found_section_count = $this_section_count;
					}
				}
			}
		}

		if(count($attribute_values) == 0)
		{
			$attribute_values = "";
		}
		else if(count($attribute_values) == 1)
		{
			$attribute_values = $attribute_values[0];
		}

		return $attribute_values;
	}
	public static function read_amd_graphics_adapters()
	{
		// Read ATI/AMD graphics hardware using aticonfig
		$adapters = array();

		if(pts_executable_in_path("aticonfig"))
		{
			$info = trim(shell_exec("aticonfig --list-adapters 2>&1"));

			foreach(explode("\n", $info) as $line)
			{
				if(($last_point = strrpos($line, ".")) > 0)
				{
					array_push($adapters, substr($line, $last_point + 3));
				}
			}
		}

		return $adapters;
	}
	public static function read_cpuinfo($attribute)
	{
		// Read CPU information
		$cpuinfo_matches = array();

		foreach(pts_to_array($attribute) as $attribute_check)
		{
			if(is_file("/proc/cpuinfo"))
			{
				$cpuinfo_lines = explode("\n", file_get_contents("/proc/cpuinfo"));

				foreach($cpuinfo_lines as $line)
				{
					$line = pts_trim_explode(": ", $line);
					$this_attribute = $line[0];
					$this_value = (count($line) > 1 ? $line[1] : "");

					if($this_attribute == $attribute_check)
					{
						array_push($cpuinfo_matches, $this_value);
					}
				}
			}

			if(count($cpuinfo_matches) != 0)
			{
				break;
			}
		}

		return $cpuinfo_matches;
	}
	public static function read_lsb_distributor_id()
	{
		$vendor = phodevi_linux_parser::read_lsb("Distributor ID");

		// Quirks for derivative distributions that don't know how to handle themselves properly
		if($vendor == "MandrivaLinux" && phodevi_linux_parser::read_lsb("Description") == "PCLinuxOS")
		{
			// PC Linux OS only stores its info in /etc/pclinuxos-release
			$vendor = false;
		}

		return $vendor;
	}
	public static function read_lsb($desc)
	{
		// Read LSB Release information, Linux Standards Base
		$info = false;

		if(pts_executable_in_path("lsb_release"))
		{
			static $output = null;

			if($output == null)
			{
				$output = shell_exec("lsb_release -a 2>&1");
			}

			if(($pos = strrpos($output, $desc . ":")) !== false)
			{
				$info = substr($output, $pos + strlen($desc) + 1);
				$info = trim(substr($info, 0, strpos($info, "\n")));
			}
		}

		return $info;
	}
	public static function read_hal($name, $UDI = null)
	{
		// Read HAL - Hardware Abstraction Layer
		$info = false;

		if(pts_executable_in_path("lshal"))
		{
			$name = pts_to_array($name);
			$remove_words = phodevi_parser::hardware_values_to_remove();

			for($i = 0; $i < count($name) && empty($info); $i++)
			{
				$info = shell_exec("lshal " . (!empty($UDI) ? "-u " . $UDI : "") . " 2>&1 | grep \"" . $name[$i] . "\"");

				if(($pos = strpos($info, $name[$i] . " = '")) !== false)
				{
					$info = substr($info, $pos + strlen($name[$i] . " = '"));
					$info = trim(substr($info, 0, strpos($info, "'")));
				}

				if(empty($info) || in_array(strtolower($info), $remove_words))
				{
					$info = false;
				}
			}
		}

		return $info;
	}
	public static function read_system_hal($name)
	{
		// Read system HAL
		$hal = phodevi_linux_parser::read_hal($name, "/org/freedesktop/Hal/devices/computer");

		if($hal == false)
		{
			$hal = phodevi_linux_parser::read_hal($name);
		}

		return $hal;
	}
	public static function read_acpi($point, $match)
	{
		// Read ACPI - Advanced Configuration and Power Interface
		$value = false;
		$point = pts_to_array($point);

		for($i = 0; $i < count($point) && empty($value); $i++)
		{
			if(is_file("/proc/acpi" . $point[$i]))
			{
				$acpi_lines = explode("\n", file_get_contents("/proc/acpi" . $point[$i]));

				for($i = 0; $i < count($acpi_lines) && $value == false; $i++)
				{
					$line = pts_trim_explode(": ", $acpi_lines[$i]);
					$this_attribute = $line[0];
					$this_value = (count($line) > 1 ? $line[1] : null);

					if($this_attribute == $match)
					{
						$value = $this_value;
					}
				}
			}
		}

		return $value;
	}
	public static function read_pci($desc, $clean_string = true)
	{
		// Read PCI bus information
		static $pci_info = null;
		$info = false;
		$desc = pts_to_array($desc);

		if($pci_info == null)
		{
			if(!is_executable("/usr/bin/lspci") && is_executable("/sbin/lspci"))
			{
				$lspci_cmd = "/sbin/lspci";
			}
			else if(($lspci = pts_executable_in_path("lspci")))
			{
				$lspci_cmd = $lspci;
			}
			else
			{
				return false;
			}

			$pci_info = shell_exec($lspci_cmd . " 2>&1");
		}

		for($i = 0; $i < count($desc) && empty($info); $i++)
		{
			if(substr($desc[$i], -1) != ":")
			{
				$desc[$i] .= ":";
			}

			if(($pos = strpos($pci_info, $desc[$i])) !== false)
			{
				$sub_pci_info = str_replace(array("[AMD]"), "", substr($pci_info, $pos + strlen($desc[$i])));
				$EOL = strpos($sub_pci_info, "\n");

				if($clean_string)
				{
					if(($temp = strpos($sub_pci_info, '/')) < $EOL && $temp > 0)
					{
						if(($temp = strpos($sub_pci_info, ' ', ($temp + 2))) < $EOL && $temp > 0)
						{
							$EOL = $temp;
						}
					}

					if(($temp = strpos($sub_pci_info, '(')) < $EOL && $temp > 0)
					{
						$EOL = $temp;
					}

					if(($temp = strpos($sub_pci_info, '[')) < $EOL && $temp > 0)
					{
						$EOL = $temp;
					}
				}

				$sub_pci_info = trim(substr($sub_pci_info, 0, $EOL));

				if(($strlen = strlen($sub_pci_info)) >= 6 && $strlen < 96)
				{
					$info = pts_clean_information_string($sub_pci_info);
				}
			}
		}

		return $info;
	}
	public static function read_sensors($attributes)
	{
		// Read LM_Sensors
		$value = false;

		if(pts_executable_in_path("sensors"))
		{
			$sensors = shell_exec("sensors 2>&1");
			$sensors_lines = explode("\n", $sensors);
			$attributes = pts_to_array($attributes);

			for($j = 0; $j < count($attributes) && empty($value); $j++)
			{
				$attribute = $attributes[$j];
				for($i = 0; $i < count($sensors_lines) && $value == false; $i++)
				{
					$line = pts_trim_explode(": ", $sensors_lines[$i]);
					$this_attribute = $line[0];

					if($this_attribute == $attribute)
					{
						$this_remainder = trim(str_replace(array('+', '°'), ' ', $line[1]));
						$this_value = substr($this_remainder, 0, strpos($this_remainder, ' '));

						if(is_numeric($this_value) && $this_value > 0)
						{
							$value = $this_value;
						}
					}
				}
			}
		}

		return $value;
	}
}

?>
