<?php // footer.php ?>
</div> <!-- .main -->

<footer class="mt-auto py-3 bg-light border-top">
  <div class="container-fluid text-center">
    <small>
      &copy; <?php echo date('Y'); ?> 
      <?php echo htmlspecialchars($school['name'] ?? 'My School System'); ?>
    </small>
  </div>
</footer>

</div> <!-- .wrapper -->

<!-- Bootstrap JS (Bundle includes Popper) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- Sidebar toggle -->
<script>
  document.getElementById('sidebarToggle')?.addEventListener('click', () => {
    document.querySelector('.sidebar')?.classList.toggle('collapsed');
  });
</script>

</body>
</html>
