<?php

require __DIR__ .'/includes/patcher_functions.php';
require __DIR__ .'/lib/base/BaseDeploy.class.php';
require __DIR__ .'/lib/Deploy.class.php';
require __DIR__ .'/lib/exceptions/DeployException.class.php';

if ($_SERVER['argc'] <= 2)
	throw new Bugbyte\Deployer\Exceptions\DeployException('Which directories ?');

$path = findRootPath($_SERVER['argv'][0], __FILE__);

$args = parseArgs($_SERVER['argv']);

$datadir_prefix = $args['datadir-prefix'];
$previous_dir = $args['previous-dir'];
unset($args['datadir-prefix'], $args['target-dir'], $args['previous-dir']);

foreach ($args as $dirname)
{
	$relative_path_offset = preg_replace('#[^/]+#', '..', $dirname);

	// Data-directories should not have been uploaded, but if they exist they should be removed.
	if (is_dir($dirname) && !is_link($dirname))
	{
		echo "rmdir($dirname)\n";
		rmdir($dirname);
	}
	// If it's already a symlink, leave it alone.
	elseif (is_link($dirname))
	{
		echo "$dirname is al een symlink\n";
		continue;
	}

	// If nothing exists at the target location a symlink can be created to the same location within the data-dir.
	if (!file_exists($dirname))
	{
		echo "symlink($relative_path_offset/$datadir_prefix/$dirname, $dirname)\n";
		symlink("$relative_path_offset/$datadir_prefix/$dirname", $dirname);
	}

	// If this directory existed in the previous deployment then it had not been moved yet, so move it now.
	if ($previous_dir && is_dir("../$previous_dir/$dirname") && !is_link("../$previous_dir/$dirname"))
	{
		echo "rename(../$previous_dir/$dirname, ../$datadir_prefix/$dirname)\n";
		rename("../$previous_dir/$dirname", "../$datadir_prefix/$dirname");
		echo "symlink($relative_path_offset/$datadir_prefix/$dirname, ../$previous_dir/$dirname)\n";
		symlink("$relative_path_offset/$datadir_prefix/$dirname", "../$previous_dir/$dirname");
	}
}
