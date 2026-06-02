(function () {
  document.querySelectorAll('pre code.language-mermaid').forEach(function (code) {
    var pre = code.parentElement;
    var graph = document.createElement('div');
    graph.className = 'mermaid';
    graph.textContent = code.textContent;
    pre.replaceWith(graph);
  });

  if (window.mermaid) {
    window.mermaid.initialize({
      startOnLoad: true,
      theme: 'neutral',
      securityLevel: 'strict'
    });
  }
}());
