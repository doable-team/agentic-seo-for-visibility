<!doctype html><html><head><?php wp_head(); ?></head><body><?php while (have_posts()) { the_post(); the_title('<h1>', '</h1>'); the_content(); } wp_footer(); ?></body></html>
