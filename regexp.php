<?php
$str = file_get_contents('text.html');

$pattern = '~(?:<b>(?:Автор|Название)(?:[: ]*)</b>)(?:[: ]*)([^: ][^<]+?[^ ])(?:\s*<br />).*(?:<b>(?:Автор|Название)(?:[: ]*)</b>)(?:[: ]*)([^: ][^<]+?[^ ])(?:\s*<br />)~';
preg_match_all ($pattern, $str, $res);

echo "<pre>";
print_r($res);
echo "</pre>";
?>