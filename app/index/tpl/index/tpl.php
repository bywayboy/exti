<!doctype html>
<html>
<head>
    <title><?php echo $title ?>11211</title>
    <style>
        body{margin:0;padding:0;text-align: center;}
    </style>
</head>

<body>
<?php foreach($vars as $key=>$item){?>
    <p><?php echo $key ?>=<?php echo $item ?></p>
<?php } ?>
</body>
</html>
