<?php
ini_set('display_startup_errors', 1);
ini_set('display_errors', 1);
error_reporting(-1);

require_once(__DIR__ . "/vendor/autoload.php");
use Pagerange\Markdown\MetaParsedown;

$loader = new \Twig\Loader\FilesystemLoader(__DIR__ . '/templates');
$twig = new \Twig\Environment($loader);
$mp = new MetaParsedown();

$config = json_decode(file_get_contents("config.json"), true);

$serve_dir = $config["serve_dir"];
function removeDir($dirname) {
    if (is_dir($dirname)) {
        $dir = new RecursiveDirectoryIterator($dirname, RecursiveDirectoryIterator::SKIP_DOTS);
        foreach (new RecursiveIteratorIterator($dir, RecursiveIteratorIterator::CHILD_FIRST) as $object) {
            if ($object->isFile()) {
                unlink($object);
            } elseif($object->isDir()) {
                rmdir($object);
            } else {
                throw new Exception('Unknown object type: '. $object->getFileName());
            }
        }
        rmdir($dirname);
    } else {
        throw new Exception('Not a directory');
    }
}
if (file_exists($serve_dir)){
    removeDir($serve_dir);
}
mkdir($serve_dir, 0777, true);

$post_directories = $config["post_directories"];
$recent_posts_dir = $config["recent_posts_directory"];

// GENERATE POSTS AND INDEX HTML FOR EACH DIRECTORY
foreach($post_directories as $post_dir){
    // create directory if it does not already exist
	$dir_path = $serve_dir . '/' . $post_dir;
	if (!is_dir($dir_path)) {
	    mkdir($dir_path, 0777, true);
	}

    $post_arr = array();

    // CONVERSION OF POSTS TO HTML
    $posts = scandir($post_dir);
    foreach($posts as $post_filename){
        if($post_filename == "." || $post_filename == "..") continue;

        $raw = file_get_contents($post_dir . '/' . $post_filename);

        $md_title = $mp->meta($raw)["title"];
        $md_body = $mp->text($raw);
        $template = $twig->load('post.html');

        // since filename conventions are e.g. 2023-02-25-POSTNAME.md
        $name_arr =  explode("-", $post_filename);
        $filename_wo_ext = pathinfo(
            implode('-', array_slice($name_arr, 3)),
            PATHINFO_FILENAME
        );

        $html_filename = $dir_path . '/' . $filename_wo_ext . '.html';
        $date = $name_arr[1] . '/' . $name_arr[2] . ' ' . $name_arr[0];

        // generate HTML and export to serve directory
        $html = $template->render([
            "md_title" => $md_title,
            "md_body" => $md_body,
            "date" => $date,
            "serve_dir" => $serve_dir,
        ]);
        file_put_contents($html_filename, $html);

        // push post title and date to post array for index generation
        array_push(
            $post_arr,
            array(
                "title"=>$md_title,
                "url"=>$filename_wo_ext . '.html',
                "date"=>$name_arr[0] . '-' . $name_arr[1] . '-' . $name_arr[2]
            )
        );
    }

    // INDEX CREATION FOR POST DIR
    $template = $twig->load('post_index.html');
    $html = $template->render([
        "posts" => array_reverse($post_arr),
        "post_dir" => $post_dir,
    ]);
    $html_filename = $dir_path . '/index.html';
    file_put_contents($html_filename, $html);

    // for generating 'recent posts' in homepage
    if ($recent_posts_dir == $post_dir){
        $n_recent_posts = $config['n_recent_posts'];
        $recent_posts = array_reverse(array_slice($post_arr,-1*$n_recent_posts));
        foreach(range(0, $n_recent_posts - 1) as $i) {
            $recent_posts[$i]['url'] = $post_dir.'/'.$recent_posts[$i]['url'];
        }
    }
}

// GENERATE HOMEPAGE

$about_me = file_get_contents('assets/about.md');
$about_me = $mp->text($about_me);

$template = $twig->load('index.html');
$html = $template->render([
    "site_title" => $config["title"],
    "recent_posts" => $recent_posts,
    "topics" => $config["post_directories"],
    "about_me" => $about_me,
]);
file_put_contents($serve_dir . '/index.html', $html);

// COPY ASSETS FOLDER TO SERVE DIR
exec('cp -r assets ' . $serve_dir);

?>
