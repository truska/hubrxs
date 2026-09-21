document.addEventListener('DOMContentLoaded', function () {
  if (!window.tinymce) return;
  window.tinymce.init({
    selector: 'textarea[name="body"]', base_url: '/js/tinymce', suffix: '.min', license_key: 'gpl',
    menubar: false, branding: false, promotion: false, height: 420, min_height: 260,
    plugins: 'autoresize code link lists wordcount',
    toolbar: 'undo redo | blocks | bold italic | bullist numlist blockquote | link unlink | removeformat | code',
    block_formats: 'Paragraph=p; Heading 2=h2; Heading 3=h3; Heading 4=h4',
    valid_elements: 'p,br,h2,h3,h4,strong/b,em/i,ul,ol,li,blockquote,hr,a[href|title|target|rel]',
    invalid_elements: 'script,style,iframe,img,form,input,button,textarea,select,object,embed',
    forced_root_block: 'p', convert_urls: false, link_assume_external_targets: 'https'
  });
});
