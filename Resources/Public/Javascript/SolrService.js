/**
 * SOLR service for Javascript
 *
 * DEPENDS ON jQuery for AJAX!
 *
 * Fairly light-weight SOLR API implementation designed for use with FED/TYPO3.
 *
 * Works by passing the request through a Proxy which rebuilds the query (i.e.
 * adds access protection parameters from TYPO3 and a few cleanups) and returns
 * SOLR's response to that new Query.
 *
 * This allows SOLR to be used easily from a Javascript/JSON context instead
 * of the provided PHP API implementation which is overkill and makes me sad.
 * SOLR is BLAZING fast and adding a complete TYPO3 request/response just to
 * facet/re-search etc. is complete overkill.
 *
 * This should make things go a little faster. Go speed racer, go!
 */

if (typeof FED == 'undefined') {
	var FED = {};
}

FED.SOLR = {

	minQueryStringLength: 3,
	limit: 500,
	perPage: 20,
	page: 1,
	fields: [
		'title^40',
		'content^10',
		'keywords^2.0',
		'tagsH1^5.0',
		'tagsH2H3^3.0',
		'tagsH4H5H6^2.0',
		'tagsInline'
	],
	proxy: '/typo3conf/ext/fed/Resources/Public/Script/SolrProxy.php',
	async: false,
	query: {},
	queryString: '',
	results: [],
	facetsApplied: [],
	facetsAvailable: [],

	search: function (queryString, facets, onResult) {
		if (typeof queryString == 'undefined') {
			return this;
		} else if (queryString.length < this.minQueryStringLength) {
			return this;
		};
		this.queryString = queryString;
		if (typeof facets == 'array') {
			this.facetsApplied = facets;
		};
		if (typeof onResult == 'function') {
			this.async = true;
			this.onResult = onResult;
			this.query = this.executeQuery(true);
		} else {
			this.query = this.executeQuery(false);
		};
		return this;
	},

	executeQuery: function() {
		var request;
		var options = {
			async: this.async,
			url: this.proxy,
			data: {
				"wt": "json",
				"json.nl": "map",
				"q": this.queryString,
				"start": parseInt(this.perPage*(this.page-1)),
				"fields": this.fields,
				"rows": this.perPage,
				"facets": this.facetsApplied
			}
		};
		if (!this.async) {
			options.onComplete = this.onResult;
			request = jQuery.ajax(options);
			var json = jQuery.parseJSON(request.responseText);
			this.results = json;
		} else {
			options.onSuccess(this.onResult);
			request = jQuery.ajax(options);
		}
	},

	onResult: function(responseText) {
		var json = jQuery.parseJSON(responseText);
		this.results = json;
	},

	setFacets: function (facets) {

	},

	addFacet: function(facet) {

	},

	getNumResults: function() {
		if (this.results.response) {
			return this.results.response.numFound;
		} else {
			return -1;
		}
	},

	getNumPages: function(perPage) {
		if (this.results.response) {
			return Math.ceil(this.results.response.numFound / perPage);
		} else {
			return -1
		};
	},

	getResultsPage: function(page, perPage) {
		if (typeof page == 'undefined') {
			page = this.page;
		};
		if (typeof perPage == 'undefined') {
			perPage = this.perPage;
		};
		this.page = page;
		this.perPage = perPage;
		this.executeQuery();
		return this.results.response.docs;
	},

	getResults: function() {
		return this.results.response.docs;
	},

	getFacets: function() {
		return this.facetsAvailable;
	},

	getAppliedFacets: function() {
		return this.facetsApplied;
	},

	setFields: function(fields) {
		this.fields = fields;
	},

	addField: function(fieldName, weight) {

	},

	removeField: function(fieldName) {

	},

	setFieldWeight: function(fieldName, weight) {

	}

};