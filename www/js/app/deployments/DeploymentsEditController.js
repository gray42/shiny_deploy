(function () {
    "use strict";

    angular
        .module('shinyDeploy')
        .controller('DeploymentsEditController', DeploymentsEditController);

    DeploymentsEditController.$inject = ['mode', '$location', '$routeParams', 'deploymentsService', 'alertsService'];

    function DeploymentsEditController(mode, $location, $routeParams, deploymentsService, alertsService) {
        /*jshint validthis: true */
        var vm = this;

        // Properties
        vm.isAdd = false;
        vm.isEdit = false;
        vm.servers = {};
        vm.repositories = {};
        vm.tasks = {};
        vm.branches = {};
        vm.deployment = {};
        vm.task = {};
        vm.taskFormMode = 'add';
        vm.apiUrl = '';

        // Methods
        vm.addDeployment = addDeployment;
        vm.updateDeployment = updateDeployment;
        vm.refreshBranches = refreshBranches;
        vm.showAddTask = showAddTask;
        vm.showEditTask = showEditTask;
        vm.addTask = addTask;
        vm.editTask = editTask;
        vm.deleteTask = deleteTask;
        vm.generateApiKey = generateApiKey;

        // Init
        init();

        /**
         * Loads data required for edit deployment form.
         */
        function init() {
            vm.isAdd = (mode === 'add');
            vm.isEdit = (mode === 'edit');

            loadServers();
            loadRepositories();
            loadTasks();

            if (vm.isEdit) {
                loadDeployment()
            }
        }

        /**
         * Requests add-deployment action on project backend.
         */
        function addDeployment() {
            vm.deployment.server_id = vm.deployment.server.id;
            vm.deployment.repository_id = vm.deployment.repository.id;
            vm.deployment.branch = vm.deployment.branchObj.id;
            deploymentsService.addDeployment(vm.deployment).then(function(data) {
                $location.path('/deployments');
                alertsService.queueAlert('Deployment successfully added.', 'success');
            }, function(reason) {
                alertsService.pushAlert(reason, 'warning');
            });
        }

        /**
         * Updates deployment data.
         */
        function updateDeployment() {
            vm.deployment.server_id = vm.deployment.server.id;
            vm.deployment.repository_id = vm.deployment.repository.id;
            vm.deployment.branch = vm.deployment.branchObj.id;
            deploymentsService.updateDeployment(vm.deployment).then(function (data) {
                alertsService.pushAlert('Deployment successfully updated.', 'success');
            }, function (reason) {
                alertsService.pushAlert(reason, 'warning');
            });
        }

        /**
         * Refresh branches list if repository is changed.
         */
        function refreshBranches() {
            deploymentsService.getRepositoryBranches(vm.deployment.repository.id).then(function (data) {
                vm.branches = data;
            }, function(reason) {
                alertsService.pushAlert(reason, 'warning');
            });
        }

        /**
         * Shows form to add new task.
         */
        function showAddTask() {
            vm.taskFormMode = 'add';
            vm.task = {};
            jQuery('#editTaskModal').modal('show');
        }

        /**
         * Shows form to edit a task.
         *
         * @param {Number} index
         */
        function showEditTask(index) {
            vm.task = vm.deployment.tasks[index];
            vm.taskFormMode = 'edit';
            jQuery('#editTaskModal').modal('show');
        }

        /**
         * Adds new task.
         */
        function addTask() {
            if (!vm.deployment.hasOwnProperty('tasks')) {
                vm.deployment.tasks = [];
            }
            vm.deployment.tasks.push(vm.task);
            vm.task = {};
            jQuery('#editTaskModal').modal('hide');
        }

        /**
         * Updates an existing task.
         */
        function editTask() {
            vm.task = {};
            jQuery('#editTaskModal').modal('hide');
        }

        /**
         * Deletes a task.
         *
         * @param {Number} index
         */
        function deleteTask(index) {
            vm.deployment.tasks.splice(index, 1);
        }

        /**
         * Trigger generation of new API key/webhook URL.
         */
        function generateApiKey() {
            deploymentsService.generateApiKey(vm.deployment).then(function (data) {
                if (!data.hasOwnProperty('apiKey')) {
                    alertsService.pushAlert('Could not generate API key.', 'warning');
                    return false;
                }
                var webhookUrl = location.protocol+'//'+location.hostname;
                webhookUrl += '/api.php?ak='+data.apiKey+'&ap='+data.password;
                vm.apiUrl = webhookUrl;
            }, function (reason) {
                alertsService.pushAlert(reason, 'warning');
            });
        }



        /*** Private Methods ******************************************************************************************/



        /**
         * Requests a list of available servers from backend.
         */
        function loadServers() {
            deploymentsService.getServers().then(function (data) {
                vm.servers = data;
            }, function(reason) {
                console.error('Error fetching servers: ' + reason);
            });
        }

        /**
         * Requests a list of available repositories from backend.
         */
        function loadRepositories() {
            deploymentsService.getRepositories().then(function (data) {
                vm.repositories = data;
            }, function(reason) {
                console.error('Error fetching repositories: ' + reason);
            });
        }

        function loadTasks() {
            deploymentsService.getTasks().then(function (data) {
                vm.tasks = data;
            }, function(reason) {
                console.error('Error fetching tasks: ' + reason);
            });
        }

        /**
         * Requests selected deployment (in edit mode) from backend.
         */
        function loadDeployment() {
            var deploymentId = ($routeParams.deploymentId) ? parseInt($routeParams.deploymentId) : 0;
            deploymentsService.getDeploymentData(deploymentId).then(function (data) {
                vm.deployment = data;
                vm.refreshBranches();
            }, function (reason) {
                $location.path('/deployments');
            });
        }
    }
})();
