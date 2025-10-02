<?php

	define ("HOSTNAME", "localhost");
	define ("USERNAME", "root");
	define ("PASSWORD", "");
	define ("DATABASE", "mscookies");
	
	$connection = mysqli_connect(HOSTNAME,USERNAME,PASSWORD,DATABASE);
	
	if(!$connection){
		die ("Connection failed");
	}
	// Connection is established silently, no message is shown.
	
	?>