<?php
	class_exists('XF\Install\App', false) || die('Invalid');

	$templater->setTitle('Upgrade complete');
?>

<div class="block">
	<div class="block-container">
		<div class="block-body block-row">
			<div class="block-rowMessage block-rowMessage--success">
				Your upgrade to <?php echo \XF::$version; ?> has completed successfully!
			</div>
		</div>
		<div class="block-footer">
			<a href="../admin.php" class="button">Enter your control panel</a>
		</div>
	</div>
</div>
