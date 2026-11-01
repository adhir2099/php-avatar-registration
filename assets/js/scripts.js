$(function () {

  const $form = $("#registerForm");
  const $loading = $(".loading-overlay");
  const $result = $("#result");

  // Handle form submission
  $form.on("submit", async function (e) {
    e.preventDefault();

    const formData = new FormData(this);

    try {
      const response = await $.ajax({
        type: "POST",
        url: "./core/signupController.php",
        data: formData,
        contentType: false,
        processData: false
      });

      if (response.trim() === "ok") {
        window.location.href = "./home.html";
      } else {
        showError("Something went wrong");
      }

    } catch (error) {
      console.error(error);
      showError("Server error. Please try again.");
    }
  });

  if ($result.length) fetchUser();

  async function fetchUser() {
    try {
      $loading?.show();

      const data = await $.ajax({
        url: "./core/signupController.php",
        method: "POST",
        data: { action: "Load" }
      });

      $result.html(data);

    } catch (error) {
      console.error(error);
      showError("Failed to load users");

    } finally {
      $loading?.hide();
    }
  }

  function showError(message) {
    const toast = $(`
        <div class="toast align-items-center text-bg-danger border-0 show position-fixed bottom-0 end-0 m-3">
          <div class="d-flex">
              <div class="toast-body">${message}</div>
              <button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast"></button>
          </div>
        </div>
    `);
    $("body").append(toast);
  }

});
