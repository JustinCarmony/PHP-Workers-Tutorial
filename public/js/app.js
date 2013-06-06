$(function(){

    // Create a model for the services
    var Worker = Backbone.Model.extend({

        // Will contain three attributes.
        // These are their default values
        defaults:{
            worker_id: '',
            worker_hash: '',
            worker_version: '12',
            time_limit: "30 minutes",
            end_time: "20 mintutes",
            last_word: "",
            last_def: "",
            status: "idle"
        }
    });

    // Create a collection of services
    var WorkerCollection = Backbone.Collection.extend({

        url:'/workers',
        // Will hold objects of the Service model
        model: Worker,

        initialize: function(){
            this.reload();
        },

        parse: function(response) {
            return response.workers;
        },
        reload: function(){
            var me = this;
            this.fetch({success: function(){
                setTimeout(function(){
                    me.reload();
                }, 1000);
            }});
        }
    });

    var WorkerView =  Backbone.View.extend({
        tagName:"div",
        template:$('#WorkerViewTpl').html(),

        render: function() {
            var tmpl = _.template(this.template); //tmpl is a function that takes a JSON object and returns html

            this.$el.html(tmpl(this.model.toJSON())); //this.el is what we defined in tagName. use $el to get access to jQuery html() function
            return this;
        }
    });

    // The main view of the application
    var WorkerListView = Backbone.View.extend({

        // Base the view on an existing element
        el: $('#worker_list'),

        initialize: function(){
            this.collection = new WorkerCollection();
            this.collection.on('all', this.render, this);
            this.render();
        },

        render: function(){
            var me = this;
            this.$el.html('');
            _.each(this.collection.models, function(item){
                me.renderWorker(item);
            }, this);

            return this;
        },

        renderWorker: function(worker)
        {
            var workerView = new WorkerView({model: worker});

            return this.$el.append(workerView.render().el);
        }

    });

    var Definition = Backbone.Model.extend({

        // Will contain three attributes.
        // These are their default values
        defaults:{
            word: '',
            def: ''
        }
    });

    // Create a collection of services
    var DefinitionCollection = Backbone.Collection.extend({

        url:'/words',
        // Will hold objects of the Service model
        model: Definition,

        initialize: function(){
            this.reload();
        },

        parse: function(response) {
            return response;
        },
        reload: function(){
            var me = this;
            this.fetch({success: function(){
                setTimeout(function(){
                    me.reload();
                }, 1000);
            }});
        }
    });

    var DefinitionView =  Backbone.View.extend({
        tagName:"div",
        template:$('#LastDefsTpl').html(),

        render: function() {
            var tmpl = _.template(this.template); //tmpl is a function that takes a JSON object and returns html

            this.$el.html(tmpl(this.model.toJSON())); //this.el is what we defined in tagName. use $el to get access to jQuery html() function
            return this;
        }
    });

    // The main view of the application
    var DefinitionListView = Backbone.View.extend({

        // Base the view on an existing element
        el: $('#def_list'),

        initialize: function(){
            this.collection = new DefinitionCollection();
            this.collection.on('all', this.render, this);
            this.render();
        },

        render: function(){
            var me = this;
            this.$el.html('');
            _.each(this.collection.models, function(item){
                me.renderDefinition(item);
            }, this);

            return this;
        },

        renderDefinition: function(worker)
        {
            var definitionView = new DefinitionView({model: worker});

            return this.$el.append(definitionView.render().el);
        }

    });

    var AppView = Backbone.View.extend({
        initialize: function(){
            this.WorkerListView = new WorkerListView();
            this.DefinitionListView = new DefinitionListView();

            $('#btnRestartWorkers').click(function(){
                $.post('/restart', {});
            });

            $('#btnLookupWords').click(function(){
                var words = $("#txtWords").val();
                $.post('/add-words', { words: words }, { success: function(){
                }});

                $('#txtWords').html('');
            });
        }
    });

    new AppView();

});